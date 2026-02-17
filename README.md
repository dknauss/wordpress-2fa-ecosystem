# WordPress Two-Factor Authentication Ecosystem

A developer-oriented reference for how major WordPress 2FA plugins store secrets, detect users, and validate codes. Useful if you're building a plugin that needs to integrate with an existing 2FA provider -- or if you're evaluating plugins for a project. 

You can use this information (and we've provided examples) for writing a simple mu-plugin bridge to connect most 2FA plugins with [Sudo for WordPress](https://github.com/dknauss/wp-sudo).

## Contents

| Document | Description |
|----------|-------------|
| [Ecosystem Survey](docs/ecosystem-survey.md) | How each major plugin stores TOTP keys, detects configured users, and validates codes. Covers 7 plugins with class names, method signatures, and storage details. |
| [Bridge Development Guide](docs/bridge-guide.md) | A pattern for building lightweight glue code between a 2FA plugin and any host plugin that delegates 2FA via hooks. Includes a generic three-hook architecture and concrete examples. |
| [bridges/](bridges/) | Drop-in example bridges for WP 2FA (Melapress), Wordfence, and AIOS. |

## Who This Is For

- **Plugin developers** who need to verify a user's 2FA status or validate a code programmatically.
- **Security auditors** comparing how plugins handle secret storage and encryption.
- **Site builders** evaluating 2FA plugins for compatibility with other tools.

## Plugins Covered

| Plugin | Active Installs | Bridgeable? | Notes |
|--------|----------------|-------------|-------|
| [Two Factor](https://wordpress.org/plugins/two-factor/) | 50,000+ | Built-in to many hosts | Provider-based API. The reference implementation. |
| [WP 2FA](https://wordpress.org/plugins/wp-2fa/) (Melapress) | 60,000+ | Yes | TOTP, email, backup codes. AES-256-CTR encrypted secrets. |
| [Wordfence Login Security](https://wordpress.org/plugins/wordfence/) | 5,000,000+ | Yes | TOTP only. Singleton class API, custom DB table. |
| [Solid Security](https://wordpress.org/plugins/better-wp-security/) | 800,000+ | Likely automatic | Bundles Two Factor provider classes internally. |
| [All-In-One Security](https://wordpress.org/plugins/all-in-one-wp-security-and-firewall/) | 1,000,000+ | Yes | Embeds Simba TFA engine. User meta storage. |
| [Shield Security](https://wordpress.org/plugins/wp-simple-firewall/) | 50,000+ | No | Deeply encapsulated, no public API. |
| [miniOrange Google Authenticator](https://wordpress.org/plugins/miniorange-2-factor-authentication/) | 20,000+ | No | Cloud-based validation, no local path. |

## Known Issues

### Two Factor plugin: Silent provider fallback

When a user enables TOTP but the REST API call that saves the secret key fails silently, the Two Factor plugin enters an inconsistent state where TOTP is listed as enabled but no key exists. `get_primary_provider_for_user()` silently falls back to Backup Codes with no warning. This has been reported upstream as [WordPress/two-factor#796](https://github.com/WordPress/two-factor/issues/796).

A related issue -- the profile form allows saving `_two_factor_enabled_providers` with TOTP listed even when no TOTP key has been validated -- is tracked at [WordPress/two-factor#797](https://github.com/WordPress/two-factor/issues/797).

## License

This research is released under [GPL-2.0-or-later](LICENSE). The example bridge code in `bridges/` is also GPL-2.0-or-later.

## Contributing

Found an inaccuracy? A plugin updated its internals? PRs and issues welcome. The ecosystem moves fast -- class names and method signatures can change between major versions.
