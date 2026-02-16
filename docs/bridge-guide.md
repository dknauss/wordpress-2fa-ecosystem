# Building a 2FA Bridge

A bridge is a small piece of glue code (typically 30-80 lines of PHP) that connects a 2FA plugin to a host plugin's reauthentication flow. This guide describes a general three-hook pattern that works for any host plugin that delegates 2FA through WordPress filters and actions.

## Table of Contents

- [The Three-Hook Pattern](#the-three-hook-pattern)
- [Hook 1: Detection](#hook-1-detection)
- [Hook 2: Rendering](#hook-2-rendering)
- [Hook 3: Validation](#hook-3-validation)
- [Handling Multiple Methods](#handling-multiple-methods)
- [Encrypted Secrets](#encrypted-secrets)
- [Constraints and Unsupported Patterns](#constraints-and-unsupported-patterns)
- [Testing Your Bridge](#testing-your-bridge)

---

## The Three-Hook Pattern

Any plugin that needs to add a 2FA verification step to a user action can delegate the work through three hooks:

| Step | Hook Type | Question It Answers |
|------|-----------|---------------------|
| **Detection** | Filter (bool) | Does this user have 2FA configured? |
| **Rendering** | Action | What form fields should the user see? |
| **Validation** | Filter (bool) | Is the submitted code correct? |

The host plugin handles everything else: page layout, form submission, nonce verification, session management, and error display. The bridge only needs to answer these three questions.

This is the pattern used by WP Sudo, and it's applicable to any WordPress plugin that wants to support pluggable 2FA.

---

## Hook 1: Detection

**Purpose:** Tell the host plugin whether the current user has 2FA configured.

```php
add_filter( 'your_host_requires_2fa', function ( bool $needs, int $user_id ): bool {
    // Don't override if another bridge already said yes.
    if ( $needs ) {
        return true;
    }

    // Check your 2FA plugin's API.
    if ( ! class_exists( 'Your_2FA_Plugin' ) ) {
        return $needs;
    }

    return Your_2FA_Plugin::user_has_2fa( $user_id );
}, 10, 2 );
```

### Key principles

1. **Respect existing detection.** If `$needs` arrives as `true`, another bridge already detected 2FA. Return `true` -- don't override it.
2. **Guard with `class_exists()`.** The bridge should be a no-op if the 2FA plugin isn't active.
3. **Return the original value if uncertain.** If your plugin doesn't manage this user, return `$needs` unchanged.

### Plugin-specific detection calls

| Plugin | Detection Call |
|--------|---------------|
| Two Factor | `Two_Factor_Core::is_user_using_two_factor( $user_id )` |
| WP 2FA | `\WP2FA\Admin\Helpers\User_Helper::is_user_using_two_factor( $user_id )` |
| Wordfence | `\WordfenceLS\Controller_Users::shared()->has_2fa_active( $user )` |
| AIOS | `get_user_meta( $user_id, 'tfa_enable_tfa', true )` |
| Solid Security | `Two_Factor_Core::is_user_using_two_factor( $user_id )` (same as Two Factor) |

---

## Hook 2: Rendering

**Purpose:** Output HTML form fields for the 2FA challenge. These fields appear inside the host plugin's form.

```php
add_action( 'your_host_render_2fa_fields', function ( \WP_User $user ): void {
    if ( ! class_exists( 'Your_2FA_Plugin' ) ) {
        return;
    }

    ?>
    <p>
        <label for="my-2fa-code">
            <?php esc_html_e( 'Verification code:', 'my-bridge' ); ?>
        </label><br />
        <input type="text"
            id="my-2fa-code"
            name="my_2fa_code"
            autocomplete="one-time-code"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="6"
            required />
    </p>
    <?php
} );
```

### Rules

- **No `<form>` wrapper.** Your fields render inside the host's existing form.
- **No submit button.** The host plugin provides its own.
- **No `action` or `_wpnonce` hidden fields.** The host handles these. Many host plugins strip and replace them to prevent conflicts.
- **Use a unique `name` attribute.** Your validation callback reads this from `$_POST`.
- **Use `autocomplete="one-time-code"`** for TOTP fields. This triggers browser and password manager autofill for OTP codes.
- **Use `inputmode="numeric"`** to show a numeric keyboard on mobile.

### Email OTP: Triggering the send

For email-based 2FA, the render hook is where you trigger sending the email:

```php
// Inside the render callback, before outputting the input:
if ( 'email' === $method ) {
    Your_2FA_Plugin::send_email_code( $user->ID );
}
```

The user sees the input field and checks their email for the code.

### Backup code fallback

If the user has backup codes configured, render a secondary input:

```php
<details>
    <summary><?php esc_html_e( 'Use a backup code instead', 'my-bridge' ); ?></summary>
    <p>
        <label for="my-2fa-backup"><?php esc_html_e( 'Backup code:', 'my-bridge' ); ?></label><br />
        <input type="text" id="my-2fa-backup" name="my_2fa_backup" autocomplete="off" />
    </p>
</details>
```

Using `<details>` keeps the backup input collapsed by default, reducing visual clutter while keeping it accessible.

---

## Hook 3: Validation

**Purpose:** Check whether the submitted code is correct.

```php
add_filter( 'your_host_validate_2fa', function ( bool $valid, \WP_User $user ): bool {
    // Don't override if another bridge already validated.
    if ( $valid ) {
        return true;
    }

    if ( ! class_exists( 'Your_2FA_Plugin' ) ) {
        return $valid;
    }

    // Read the submitted code.
    // Note: The host plugin has already verified the nonce.
    $code = isset( $_POST['my_2fa_code'] )
        ? sanitize_text_field( wp_unslash( $_POST['my_2fa_code'] ) )
        : '';

    if ( ! empty( $code ) ) {
        if ( Your_2FA_Plugin::verify_code( $user->ID, $code ) ) {
            return true;
        }
    }

    // Try backup code if provided.
    $backup = isset( $_POST['my_2fa_backup'] )
        ? sanitize_text_field( wp_unslash( $_POST['my_2fa_backup'] ) )
        : '';

    if ( ! empty( $backup ) ) {
        if ( Your_2FA_Plugin::verify_backup_code( $user->ID, $backup ) ) {
            return true;
        }
    }

    return false;
}, 10, 2 );
```

### Key principles

1. **Respect existing validation.** If `$valid` is `true`, return `true`.
2. **Don't verify the nonce.** The host plugin already did this.
3. **Sanitize inputs.** Always `sanitize_text_field( wp_unslash() )` on `$_POST` values.
4. **Try primary method first, then fallback.** Check the main code input, then backup codes.
5. **Return `false` explicitly on failure.** Don't return `$valid` (which is `false`) -- be explicit.

### Plugin-specific validation calls

| Plugin | TOTP Validation |
|--------|----------------|
| Two Factor | `$provider->validate_authentication( $user )` (reads from `$_POST` internally) |
| WP 2FA | `Authentication::is_valid_authcode( $key, $code )` |
| Wordfence | `Controller_TOTP::shared()->validate_2fa( $user, $code )` |
| AIOS | `$simba_two_factor_authentication->authUserFromLogin( $params )` |

---

## Handling Multiple Methods

When a user has multiple 2FA methods (e.g., TOTP primary + backup codes), the bridge needs to:

1. **Detect the primary method** and render the appropriate input.
2. **Always show backup codes** as a fallback when available.
3. **Try the primary method first** in validation, then fall back to backup codes.

```php
// In the render callback:
$method = Your_2FA_Plugin::get_user_method( $user );

if ( 'totp' === $method ) {
    // Render TOTP input
} elseif ( 'email' === $method ) {
    // Send email code and render email input
}

// Always render backup code input if available
if ( Your_2FA_Plugin::user_has_backup_codes( $user ) ) {
    // Render backup code input (in a <details> block)
}
```

---

## Encrypted Secrets

Several plugins encrypt their TOTP secrets at rest:

| Plugin | Encryption | What This Means for Your Bridge |
|--------|-----------|-------------------------------|
| WP 2FA | AES-256-CTR | Pass the encrypted key to `is_valid_authcode()` -- it decrypts internally |
| Solid Security | `ITSEC_ENCRYPTION_KEY` | Use the Two Factor API -- decryption is handled by the provider |
| Wordfence | Proprietary | Call `validate_2fa()` -- decryption is internal |

**The rule:** Never try to decrypt secrets yourself. Always use the plugin's own validation methods, which handle decryption transparently.

---

## Constraints and Unsupported Patterns

### Patterns that don't work as bridges

1. **Cloud-based validation.** If the 2FA plugin validates codes through a remote API (miniOrange), latency and error handling make synchronous integration unreliable.

2. **JavaScript-only methods.** If the method requires a browser ceremony (WebAuthn/passkeys), you need to enqueue scripts on the challenge page and populate a hidden field with the result. This is doable but requires more than a simple PHP bridge.

3. **Push notification methods.** Methods where the user approves on a separate device don't fit a synchronous form-submit model. A polling-based approach would be needed.

4. **Deeply encapsulated plugins.** Some plugins (Shield Security) have no stable public API. Depending on internal methods creates fragile bridges that break on updates.

### What makes a plugin bridgeable?

A 2FA plugin is easily bridgeable if it exposes:

- A way to check if a user has 2FA enabled (detection).
- A way to validate a code against the user's secret (validation).
- Optional: a way to identify the active method (TOTP vs email vs backup).

If the plugin stores secrets in user meta and has public static methods or documented class APIs for validation, writing a bridge is straightforward. If it uses custom tables, singleton patterns, or cloud APIs, it gets harder.

---

## Testing Your Bridge

### Manual test procedure

1. Activate the 2FA plugin and the host plugin.
2. Configure 2FA for a test user (TOTP, email, or both).
3. Drop the bridge file into `mu-plugins/`.
4. Trigger the host plugin's gated action.
5. Verify the flow:
   - The 2FA challenge appears after password verification.
   - A correct code passes validation and completes the action.
   - A wrong code shows an error message.
   - Backup codes work when the primary method fails.

### Automated testing

Bridges are standard WordPress filters and actions. You can test them with Brain\Monkey, WP_Mock, or any WordPress mocking library:

```php
// Test detection
$needs = apply_filters( 'your_host_requires_2fa', false, $user_id );
$this->assertTrue( $needs );

// Test validation with a valid code
$_POST['my_2fa_code'] = '123456';
$valid = apply_filters( 'your_host_validate_2fa', false, $user );
$this->assertTrue( $valid );

// Test validation with wrong code
$_POST['my_2fa_code'] = '000000';
$valid = apply_filters( 'your_host_validate_2fa', false, $user );
$this->assertFalse( $valid );

// Test that an already-valid result is not overridden
$valid = apply_filters( 'your_host_validate_2fa', true, $user );
$this->assertTrue( $valid );
```

### Testing tips

- **Test with the 2FA plugin deactivated.** The bridge should be a silent no-op (all `class_exists()` checks should fail gracefully).
- **Test with multiple bridges active.** If you have bridges for two different 2FA plugins, the `$needs`/`$valid` pass-through logic should allow both to coexist.
- **Test backup code consumption.** Most plugins invalidate backup codes after use. Verify a used backup code is rejected on the second attempt.
