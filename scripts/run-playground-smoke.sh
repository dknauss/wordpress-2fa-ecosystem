#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BLUEPRINT="$ROOT_DIR/tests/playground/bridge-smoke.blueprint.json"
WP_VERSION="${WP_PLAYGROUND_WP_VERSION:-latest}"
PHP_VERSION="${WP_PLAYGROUND_PHP_VERSION:-8.3}"
NPM_CACHE_DIR="${WP_PLAYGROUND_NPM_CACHE:-$HOME/.cache/wp-playground-smoke-npm}"

mkdir -p "$NPM_CACHE_DIR"
export NPM_CONFIG_CACHE="$NPM_CACHE_DIR"

echo "Running real-plugin Playground smoke test (WP ${WP_VERSION}, PHP ${PHP_VERSION})..."

npx -y @wp-playground/cli@latest run-blueprint \
  --mount "$ROOT_DIR:/workspace" \
  --blueprint "$BLUEPRINT" \
  --wp "$WP_VERSION" \
  --php "$PHP_VERSION" \
  --verbosity=normal
