#!/usr/bin/env bash
# Installs this package into a fresh Laravel skeleton and runs the first-day steps (spec 006, R4):
# gate:install, gate:check and the spec 001 quickstart (an empty tools/list over stdio).
#
#   scripts/install-test.sh 12|13
#
# Composer and artisan output goes to stderr; stdout carries only the three ok lines.
set -euo pipefail

usage() {
    echo "usage: $0 12|13" >&2
    exit 2
}

[[ $# -eq 1 ]] || usage
case "$1" in
    12 | 13) ;;
    *) usage ;;
esac
VERSION="$1"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
APP="$WORK/app"

composer create-project --no-interaction --prefer-dist "laravel/laravel:^${VERSION}.0" "$APP" >&2

cd "$APP"
composer config repositories.context-gate path "$ROOT" >&2
composer require --no-interaction everrest/context-gate:@dev >&2
php artisan gate:install --no-interaction >&2
php artisan migrate --force --no-interaction >&2
echo "install ok"

php artisan gate:check >&2
echo "gate:check ok"

INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"install-test","version":"1"}}}'
LIST='{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
RESPONSE="$(printf '%s\n%s\n' "$INIT" "$LIST" | php artisan mcp:start context-gate 2>/dev/null)"
if [[ "$RESPONSE" != *'"tools":[]'* ]]; then
    echo "quickstart: tools/list did not return an empty tool list" >&2
    exit 1
fi
echo "quickstart ok"
