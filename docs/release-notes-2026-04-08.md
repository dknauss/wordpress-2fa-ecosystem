# Release Notes — 2026-04-08

## Bridge hardening

- Fixed the AIOS example bridge so it restores any pre-existing `$_POST['two_factor_code']` value after validation, preventing request-global state leakage between hooks and bridges.
- Hardened the AIOS bridge runtime checks so stale user meta does not force a broken 2FA challenge when the AIOS Simba runtime is unavailable.
- Hardened the WP 2FA email bridge so repeated renders do not regenerate and invalidate the current email code for the same challenge.

## Verification

- Added a repeatable WordPress Playground smoke test that installs the real AIOS and WP 2FA plugins from WordPress.org and exercises the example bridges against vendor code.
- Added inactive-plugin smoke coverage so the example bridges continue to silently no-op when their backing plugin runtime is unavailable.
- Added a GitHub Actions workflow to run the Playground smoke test on pushes and pull requests across a small WordPress/PHP matrix.
