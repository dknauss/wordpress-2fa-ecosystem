#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BLUEPRINT="$ROOT_DIR/tests/playground/bridge-smoke.blueprint.json"

echo "Running real-plugin Playground smoke test..."

npx -y @wp-playground/cli@latest run-blueprint \
  --mount "$ROOT_DIR:/workspace" \
  --blueprint "$BLUEPRINT" \
  --verbosity=normal
