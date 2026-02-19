# WordPress 2FA Plugin Ecosystem Survey

How the major WordPress two-factor authentication plugins detect configured users, store secrets, and validate codes. All class names and method signatures are current as of early 2026 and were verified against the plugin source code.

## Table of Contents

- [Two Factor (WordPress/two-factor)](#two-factor-wordpresstwo-factor)
- [WP 2FA (Melapress)](#wp-2fa-melapress)
- [Wordfence Login Security](#wordfence-login-security)
- [Solid Security (formerly iThemes Security)](#solid-security)
- [All-In-One Security (AIOS)](#all-in-one-security-aios)
- [Shield Security](#shield-security)
- [miniOrange Google Authenticator](#miniorange-google-authenticator)
- [Comparison Matrix](#comparison-matrix)

---

## Two Factor (WordPress/two-factor)

**Plugin:** [wordpress.org/plugins/two-factor](https://wordpress.org/plugins/two-factor/)
**Active installs:** 100,000+
**Architecture:** Provider-based. Each 2FA method (TOTP, email, backup codes, WebAuthn) is a class extending `Two_Factor_Provider`.

### Detection

```php
Two_Factor_Core::is_user_using_two_factor( $user_id )
```

Returns `true` if the user has at least one enabled provider listed in `_two_factor_enabled_providers` user meta **and** that provider's `is_available_for_user()` returns `true`.

### Getting the active provider

```php
$provider = Two_Factor_Core::get_primary_provider_for_user( $user );
```

Returns the provider object set in `_two_factor_provider` user meta. If the primary provider is unavailable, silently falls back to the first available enabled provider.

### Validation

```php
$provider->validate_authentication( $user );
```

Each provider reads its own fields from `$_POST`. For TOTP, the field is `authcode`. For email, it's `two-factor-email-code`. For backup codes, it's `two-factor-backup-code`.

### Storage

| What | Where | Format |
|------|-------|--------|
| Enabled providers | `_two_factor_enabled_providers` user meta | Serialized array of class names |
| Primary provider | `_two_factor_provider` user meta | Class name string |
| TOTP secret | `_two_factor_totp_key` user meta | Base32-encoded plaintext |
| Email token | `_two_factor_email_token` user meta | Hashed token |
| Backup codes | `_two_factor_backup_codes` user meta | JSON array of hashed codes |

### Notes

- The TOTP secret is stored in **plaintext** (base32-encoded). This is a deliberate design choice -- the plugin assumes the database is the trust boundary.
- The provider API is fully extensible. Third-party plugins can register new providers by extending `Two_Factor_Provider` and filtering `two_factor_providers`.
- **Known issue:** If the TOTP key fails to save (REST API error during setup), the plugin silently falls back to backup codes. See [#796](https://github.com/WordPress/two-factor/issues/796).

---

## WP 2FA (Melapress)

**Plugin:** [wordpress.org/plugins/wp-2fa](https://wordpress.org/plugins/wp-2fa/)
**Active installs:** 90,000+
**Architecture:** Centralized helper classes. Methods are identified by string slugs (`'totp'`, `'email'`).

### Detection

```php
\WP2FA\Admin\Helpers\User_Helper::is_user_using_two_factor( $user_id )
```

### Method identification

```php
$method = \WP2FA\Admin\Helpers\User_Helper::get_enabled_method_for_user( $user );
// Returns: 'totp', 'email', or empty string
```

Note: This accepts either a `WP_User` object or a user ID.

### TOTP validation

```php
// Get the user's TOTP key (returned in encrypted form)
$key = \WP2FA\Methods\TOTP::get_totp_key( $user );

// Validate a 6-digit code against the key
$valid = \WP2FA\Authenticator\Authentication::is_valid_authcode( $key, $code );
```

The `is_valid_authcode()` method handles decryption internally. You pass the encrypted key directly.

### Email OTP validation

```php
// Generate and send the email token
\WP2FA\Authenticator\Authentication::generate_token( $user->ID );

// Validate the submitted token
$valid = \WP2FA\Authenticator\Authentication::validate_token( $user, $code );
```

### Backup codes

```php
$valid = \WP2FA\Methods\Backup_Codes::validate_code( $user, $code );
```

### Storage

| What | Where | Format |
|------|-------|--------|
| TOTP secret | `wp_2fa_totp_key` user meta | AES-256-CTR encrypted |
| Enabled method | User meta (via `User_Helper`) | String slug |
| Backup codes | `wp_2fa_backup_codes` user meta | Encrypted array |
| Email tokens | Transient-based | Hashed |

### Notes

- Secrets are encrypted with AES-256-CTR using a key derived from WordPress salts. This is a significant security advantage over plaintext storage.
- The encryption is transparent to the validation API -- you never need to decrypt manually.
- The free version supports TOTP and email. Backup codes and additional methods are in the premium version.

---

## Wordfence Login Security

**Plugin:** [wordpress.org/plugins/wordfence](https://wordpress.org/plugins/wordfence/)
**Active installs:** 5,000,000+ (full Wordfence suite)
**Architecture:** Singleton controllers. No public hooks for 2FA operations.

### Detection

```php
\WordfenceLS\Controller_Users::shared()->has_2fa_active( $user )
```

The `$user` parameter is a `WP_User` object (not a user ID).

### Validation

```php
$valid = \WordfenceLS\Controller_TOTP::shared()->validate_2fa( $user, $code );
```

### Storage

| What | Where | Format |
|------|-------|--------|
| TOTP secret | Custom database table (`wp_wfls_2fa_secrets`) | Encrypted |
| Recovery codes | Same custom table | Hashed |
| Settings | `wp_wfls_settings` option | Serialized |

### Notes

- Wordfence does **not** use WordPress user meta for 2FA storage. Everything is in custom tables.
- The singleton pattern (`::shared()`) means you call methods on the shared instance, not static methods.
- There are no public filter/action hooks for 2FA operations. Integration requires calling the controller methods directly.
- The Login Security module can be installed standalone (separate plugin) or as part of the full Wordfence suite.

---

## Solid Security

**Plugin:** [wordpress.org/plugins/better-wp-security](https://wordpress.org/plugins/better-wp-security/) (formerly iThemes Security)
**Active installs:** 700,000+
**Architecture:** Bundles the Two Factor plugin's provider classes internally.

### Detection

Uses the same user meta as the Two Factor plugin:

```php
// Check if Two_Factor_Core class exists (Solid Security registers it)
if ( class_exists( 'Two_Factor_Core' ) ) {
    $has_2fa = Two_Factor_Core::is_user_using_two_factor( $user_id );
}
```

### Validation

Two Factor-compatible provider pattern. If `class_exists( 'Two_Factor_Core' )` returns `true`, the standard Two Factor API works.

### Storage

| What | Where | Format |
|------|-------|--------|
| TOTP secret | `_two_factor_totp_key` user meta | Encrypted with `ITSEC_ENCRYPTION_KEY` |
| Enabled providers | `_two_factor_enabled_providers` user meta | Serialized array |
| Primary provider | `_two_factor_provider` user meta | Class name string |

### Notes

- Because Solid Security bundles Two Factor provider classes, integrations that already work with the Two Factor plugin often work automatically with Solid Security.
- The TOTP key is encrypted (unlike the standalone Two Factor plugin which stores plaintext). Solid Security uses its own `ITSEC_ENCRYPTION_KEY` constant or a derived key.
- Test by checking `class_exists( 'Two_Factor_Core' )` -- if true, use the standard Two Factor API.

---

## All-In-One Security (AIOS)

**Plugin:** [wordpress.org/plugins/all-in-one-wp-security-and-firewall](https://wordpress.org/plugins/all-in-one-wp-security-and-firewall/)
**Active installs:** 1,000,000+
**Architecture:** Embeds the Simba Two Factor Authentication engine.

### Detection

```php
$enabled = get_user_meta( $user_id, 'tfa_enable_tfa', true );
// Returns truthy value if 2FA is enabled for this user
```

### Validation

```php
global $simba_two_factor_authentication;

if ( $simba_two_factor_authentication && method_exists( $simba_two_factor_authentication, 'authorise_user_from_login' ) ) {
    $params = array(
        'log' => $user->user_login,
        'caller' => 'external',  // identifies the calling context
    );
    $result = $simba_two_factor_authentication->authorise_user_from_login( $params );
}
```

**Important:** The Simba TFA engine reads the code from `$_POST['two_factor_code']` internally. You need to copy your submitted field value into that key before calling `authorise_user_from_login()`:

```php
$_POST['two_factor_code'] = $submitted_code;
```

### Storage

| What | Where | Format |
|------|-------|--------|
| TOTP secret | `tfa_priv_key_64` user meta | Base64-encoded |
| Enabled flag | `tfa_enable_tfa` user meta | Boolean-ish |
| Trusted devices | `tfa_trusted_devices` user meta | Serialized array |

### Notes

- AIOS wraps the Simba TFA library as a global object. You must access it via the `$simba_two_factor_authentication` global.
- The `authorise_user_from_login()` method has side effects -- it reads from `$_POST` directly. Plan accordingly.
- The TOTP secret is stored as base64, not encrypted. Similar trust model to the Two Factor plugin.

---

## Shield Security

**Plugin:** [wordpress.org/plugins/wp-simple-firewall](https://wordpress.org/plugins/wp-simple-firewall/)
**Active installs:** 40,000+
**Architecture:** Deep container/controller system with heavy internal abstraction.

### Detection

No straightforward public method. 2FA status is managed through Shield's module system:

```php
// Approximate internal path (not a stable API):
$controller->getModule_LoginGuard()->getHandlerGoogleAuth()->processOtp()
```

### Validation

```php
// Internal — no stable public API
GoogleAuth->processOtp()
```

### Storage

| What | Where | Format |
|------|-------|--------|
| TOTP secret | Custom database table | Encrypted |
| 2FA status | Custom database table | Internal flags |

### Notes

- Shield's architecture is deeply encapsulated. There is no practical way to call detection or validation methods from outside the plugin without depending on internal implementation details that change between versions.
- **Not recommended for programmatic integration.** If you need to check 2FA status for a Shield user, consider using Shield's own hooks (if any are documented for your use case) or contact the Shield team directly.

---

## miniOrange Google Authenticator

**Plugin:** [wordpress.org/plugins/miniorange-2-factor-authentication](https://wordpress.org/plugins/miniorange-2-factor-authentication/)
**Active installs:** 10,000+
**Architecture:** Cloud-first. Most 2FA validation happens through miniOrange's servers.

### Detection

```php
$method = get_user_meta( $user_id, 'currentMethod', true );
// Returns method name string like 'Google Authenticator', 'miniOrange Soft Token', etc.
// Note: miniOrange has restructured its meta storage across versions. The meta key
// 'mo2f_configured_2FA_method' may appear nested inside array-valued meta entries
// rather than as a standalone key. Always verify against the installed version.
```

### Validation

For the hosted/cloud methods, validation requires an API call to miniOrange servers:

```php
// No local validation path for cloud-based methods
// The plugin sends the code to api.miniorange.com for verification
```

### Storage

| What | Where | Format |
|------|-------|--------|
| Current method | `currentMethod` user meta | String |
| Configuration status | `mo2f_configured_2FA_method` (nested in array meta) | String |
| Secret (if local) | Varies by method | May be stored locally or in miniOrange cloud |

### Notes

- The reliance on cloud API calls makes this plugin unsuitable for synchronous, local-only integration.
- Some methods may have local validation paths (basic Google Authenticator TOTP), but the plugin's architecture does not expose a clean local-only validation API.
- **Not recommended for programmatic integration** unless you're willing to depend on miniOrange's external API.

---

## Comparison Matrix

| Feature | Two Factor | WP 2FA | Wordfence | Solid Security | AIOS | Shield | miniOrange |
|---------|-----------|--------|-----------|---------------|------|--------|------------|
| **Public detection API** | Yes | Yes | Yes | Yes (via Two Factor) | Partial | No | Partial |
| **Public validation API** | Yes | Yes | Yes | Yes (via Two Factor) | Yes (global) | No | No |
| **Secret encryption** | No (plaintext) | AES-256-CTR | Yes | Yes | No (base64) | Yes | Varies |
| **Storage location** | User meta | User meta | Custom table | User meta | User meta | Custom table | User meta / cloud |
| **TOTP support** | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| **Email OTP** | Yes | Yes | No | Yes | No | Yes | Yes |
| **Backup codes** | Yes | Yes (premium) | Yes | Yes | No | Yes | Yes |
| **WebAuthn/Passkeys** | Yes | No | No | Yes | No | No | No |
| **Extensible API** | Provider system | No | No | Provider system | No | No | No |
| **Bridgeable?** | Built-in | Yes | Yes | Likely automatic | Yes | No | No |

### Secret Encryption Detail

| Plugin | Encryption | Key Derivation |
|--------|-----------|----------------|
| Two Factor | None (base32 plaintext) | N/A |
| WP 2FA | AES-256-CTR | WordPress salts |
| Wordfence | Yes (proprietary) | Internal |
| Solid Security | Yes | `ITSEC_ENCRYPTION_KEY` or derived |
| AIOS | None (base64 encoding only) | N/A |
| Shield | Yes | Internal |
| miniOrange | Varies | Cloud-managed |
