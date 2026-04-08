#!/usr/bin/env bash
set -euo pipefail

TMPDIR_WORDfENCE="$(mktemp -d)"
cleanup() {
  rm -rf "$TMPDIR_WORDfENCE"
}
trap cleanup EXIT

cd "$TMPDIR_WORDfENCE"
curl -L -o wordfence.zip https://downloads.wordpress.org/plugin/wordfence.latest-stable.zip >/dev/null 2>&1
unzip -q wordfence.zip

echo "Checking Wordfence bridge target APIs against the current plugin package..."

rg -q "class Controller_Users" wordfence/modules/login-security/classes/controller/users.php
rg -q "class Controller_TOTP" wordfence/modules/login-security/classes/controller/totp.php
rg -q "has_2fa_active\\(" wordfence/modules/login-security/classes/controller/users.php
rg -q "validate_2fa\\(" wordfence/modules/login-security/classes/controller/totp.php

echo "Wordfence bridge target APIs verified."
