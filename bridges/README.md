# Example 2FA Bridges

These are working examples of the three-hook bridge pattern described in the [Bridge Development Guide](../docs/bridge-guide.md). Each bridge connects a specific 2FA plugin to a host plugin's reauthentication challenge.

## Usage

1. Copy the bridge file for your 2FA plugin to `wp-content/mu-plugins/`.
2. Replace the hook names (`wp_sudo_requires_two_factor`, etc.) with your host plugin's actual hook names.
3. Test with a user who has 2FA configured.

## Available Bridges

| File | 2FA Plugin | Methods Supported | Lines |
|------|-----------|-------------------|-------|
| `wp2fa-bridge.php` | WP 2FA (Melapress) | TOTP, email OTP, backup codes | ~170 |
| `wordfence-bridge.php` | Wordfence Login Security | TOTP | ~100 |
| `aios-bridge.php` | AIOS (Simba TFA) | TOTP | ~110 |

## Notes

- As written, these bridges use **WP Sudo's hook names** as concrete examples. If you're integrating with a different host plugin, change the hook names in `add_filter()` and `add_action()` calls.
- All bridges are safe to load when the 2FA plugin is not active -- `class_exists()` checks ensure they silently no-op.
- All bridges respect the `$needs`/`$valid` pass-through pattern, allowing multiple bridges to coexist.
