#!/usr/bin/env bash
# Release guards: changelog heading and body, version constant, reporting placeholder. No network.
# Usage: scripts/check-release.sh [repo-root]   (default: the directory above this script)
set -euo pipefail

root="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$root"

fail() {
    echo "check-release: FAILED ($1 guard): $2" >&2
    exit 1
}

[[ -f src/ContextGate.php ]] || fail version "src/ContextGate.php is missing"
version="$(sed -n "s/.*const VERSION = '\([^']*\)';.*/\1/p" src/ContextGate.php | sed -n '1p')"
[[ -n "$version" ]] || fail version "no VERSION constant in src/ContextGate.php"

# Changelog: an exact, anchored heading for the version, followed by at least one bullet before the next heading.
[[ -f CHANGELOG.md ]] || fail changelog "CHANGELOG.md is missing"
grep -Fxq "## [$version]" CHANGELOG.md || fail changelog "CHANGELOG.md has no heading '## [$version]'"
bullets="$(awk -v heading="## [$version]" '
    $0 == heading { inside = 1; next }
    inside && /^## / { exit }
    inside && /^- / { count++ }
    END { print count + 0 }
' CHANGELOG.md)"
[[ "$bullets" -gt 0 ]] || fail changelog "the '## [$version]' section has no bullet"

# Version: the constant must match the newest versioned heading, so a new heading cannot ship with an old constant.
newest="$(sed -n 's/^## \[\([^]]*\)\]$/\1/p' CHANGELOG.md | sed -n '1p')"
[[ "$newest" == "$version" ]] || fail version "VERSION is '$version' but the newest changelog heading is '${newest:-none}'"

# Placeholder: the reporting address must be set before a release.
[[ -f SECURITY.md ]] || fail placeholder "SECURITY.md is missing"
if grep -Fq '<REPORTING_ADDRESS>' SECURITY.md; then
    fail placeholder "SECURITY.md still contains <REPORTING_ADDRESS>"
fi

echo "check-release: all guards passed for $version"
