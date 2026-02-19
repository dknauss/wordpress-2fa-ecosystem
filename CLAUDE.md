# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A developer-oriented reference for how major WordPress 2FA plugins store secrets, detect users, and validate codes. Includes example bridge code for connecting 2FA plugins to host plugins (like WP Sudo) via a three-hook pattern.

## Repository Structure

- `docs/ecosystem-survey.md` — Per-plugin technical details: class names, method signatures, meta keys, storage mechanisms.
- `docs/bridge-guide.md` — The three-hook bridge pattern with concrete examples.
- `bridges/` — Drop-in example bridges for WP 2FA (Melapress), Wordfence, and AIOS.
- `README.md` — Summary table with install counts and bridgeability notes.

## Verification Requirements

This repository documents third-party plugin internals. LLM-generated content has
a documented history of confabulation here — fabricated method names, invented meta
keys, and stale install counts were committed without verification and shipped as
authoritative reference material. See `../wp-sudo/llm_lies_log.txt` for the full
record.

**Every claim in this repo must be verified against a live source before committing.**

### Method names, class names, meta keys, storage details

- **MUST** verify against WordPress.org SVN trunk or GitHub raw source before writing.
- **MUST** note the plugin version or SVN revision checked.
- If unable to verify, **MUST** say so explicitly — never guess or rely on training data.
- When updating, include the verification URL in the commit message.

### Verification commands

```bash
# WordPress.org SVN — view a plugin's trunk source
# Replace PLUGIN-SLUG and FILE-PATH as needed.
curl -s "https://plugins.svn.wordpress.org/PLUGIN-SLUG/trunk/FILE-PATH"

# AIOS / Simba TFA — verify class and method names
curl -s "https://plugins.svn.wordpress.org/all-in-one-wp-security-and-firewall/trunk/classes/wp-security-two-factor-login.php" | grep -E "class |function "
curl -s "https://plugins.svn.wordpress.org/all-in-one-wp-security-and-firewall/trunk/classes/simba-tfa/simba-tfa.php" | grep -E "function |get_user_meta|update_user_meta"

# WP 2FA (Melapress) — verify namespaces and methods
curl -s "https://plugins.svn.wordpress.org/wp-2fa/trunk/includes/classes/Authenticator/class-totp.php" | grep -E "class |function |namespace "

# Wordfence — verify controller classes and table names
curl -s "https://plugins.svn.wordpress.org/wordfence/trunk/modules/login-security/classes/controller.php" | grep -E "class |function "

# Two Factor (GitHub-hosted)
curl -s "https://raw.githubusercontent.com/WordPress/two-factor/master/class-two-factor-core.php" | grep -E "class |function "

# miniOrange — verify meta keys and class structure
curl -s "https://plugins.svn.wordpress.org/miniorange-2-factor-authentication/trunk/handler/class-mo2fdb.php" | grep -E "function |get_user_meta|update_user_meta|'[a-zA-Z_]*'"

# Plugin install counts — query the Plugin Info API, never use training data
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=two-factor" | jq '.active_installs'
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=wp-2fa" | jq '.active_installs'
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=wordfence" | jq '.active_installs'
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=better-wp-security" | jq '.active_installs'
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=all-in-one-wp-security-and-firewall" | jq '.active_installs'
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=wp-simple-firewall" | jq '.active_installs'
curl -s "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=miniorange-2-factor-authentication" | jq '.active_installs'
```

### Install counts

- **MUST** come from the WordPress.org Plugin Info API. Never from training data.
- **MUST** note the query date in a comment or commit message.
- Re-verify before any release or README update.

### Pre-commit audit

Before committing changes to ecosystem-survey.md, bridge-guide.md, or any bridge
PHP file, verify every method name, class name, and meta key referenced in the diff
against SVN trunk or GitHub source. If anything cannot be verified, flag it with a
`<!-- UNVERIFIED -->` comment rather than presenting it as fact.

## Bridge Code Conventions

- Bridges use the three-hook pattern: detection filter, render action, validation filter.
- All bridge files go in `bridges/` with the naming convention `PLUGIN-bridge.php`.
- Bridge code should include `phpcs:ignore` comments where WordPress nonce verification is handled by the host plugin.
- Use `method_exists()` and `class_exists()` guards before calling third-party code.

## License

GPL-2.0-or-later. All example bridge code is also GPL-2.0-or-later.
