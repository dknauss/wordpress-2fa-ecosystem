# Changelog

## Unreleased

### Added

- README workflow badges for `PHP Lint` and `Playground Smoke`.
- Real-plugin Playground smoke coverage for the WP 2FA backup-code fallback path.
- Source-grounded compatibility verification for the Wordfence bridge against the current Wordfence plugin package.

### Changed

- Expanded documentation around local testing and CI coverage.

## v0.1.0 - 2026-04-08

### Bridge hardening

- Fixed the AIOS example bridge so it restores any pre-existing `$_POST['two_factor_code']` value after validation, preventing request-global state leakage between hooks and bridges.
- Hardened the AIOS bridge runtime checks so stale user meta does not force a broken 2FA challenge when the AIOS Simba runtime is unavailable.
- Hardened the WP 2FA email bridge so repeated renders do not regenerate and invalidate the current email code for the same challenge.

### Verification

- Added a repeatable WordPress Playground smoke test that installs the real AIOS and WP 2FA plugins from WordPress.org and exercises the example bridges against vendor code.
- Added inactive-plugin smoke coverage so the example bridges continue to silently no-op when their backing plugin runtime is unavailable.
- Added GitHub Actions workflows for PHP linting and Playground smoke verification.
