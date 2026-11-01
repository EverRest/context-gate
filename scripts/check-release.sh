#!/usr/bin/env bash
# Release guards: changelog heading and body, version constant, reporting placeholder. No network.
# Usage: scripts/check-release.sh [repo-root]   (default: the directory above this script)
#        scripts/check-release.sh <pinned|annotated|tip|version|changelog> <tag>   (run by release.yml, in the repo)
# Tag guards check the tag's commit, not the working tree. RELEASE_SHA (pinned) is the commit the run was started for.
set -euo pipefail

fail() {
    echo "check-release: FAILED ($1 guard): $2" >&2
    exit 1
}

tag_guard() {
    local guard=$1 tag=$2 commit version got
    version=${tag#v}
    commit=$(git rev-parse --verify -q "refs/tags/$tag^{commit}") || fail "$guard" "tag $tag does not exist"
    case "$guard" in
    pinned)
        : "${RELEASE_SHA:?RELEASE_SHA is not set}"
        [[ "$commit" == "$RELEASE_SHA" ]] || fail pinned "$tag now points at $commit, but this run was started for $RELEASE_SHA"
        ;;
    annotated)
        [[ "$(git cat-file -t "refs/tags/$tag")" == tag ]] || fail annotated "$tag is a lightweight tag; create it with git tag -a"
        ;;
    tip)
        git rev-parse --verify -q refs/remotes/origin/master >/dev/null \
            || fail tip "refs/remotes/origin/master is missing; fetch origin before releasing"
        [[ "$commit" == "$(git rev-parse refs/remotes/origin/master)" ]] \
            || fail tip "$tag ($commit) is not the tip of origin/master"
        ;;
    version)
        got=$(git show "$commit:src/ContextGate.php" 2>/dev/null | sed -n "s/.*const VERSION = '\([^']*\)';.*/\1/p" | sed -n '1p') \
            || fail version "$tag has no src/ContextGate.php"
        [[ "$got" == "$version" ]] || fail version "VERSION is '${got:-none}', but $tag says $version"
        ;;
    changelog)
        git show "$commit:CHANGELOG.md" 2>/dev/null | awk -v heading="## [$version]" '
            $0 == heading { inside = 1; next }
            inside && /^## / { exit }
            inside && /^- / { count++ }
            END { exit (count > 0 ? 0 : 1) }
        ' || fail changelog "CHANGELOG.md has no '## [$version]' section with a bullet at $tag"
        ;;
    esac
    echo "check-release: $guard guard passed for $tag"
}

case "${1:-}" in
pinned | annotated | tip | version | changelog)
    tag_guard "$1" "${2:?usage: check-release.sh <pinned|annotated|tip|version|changelog> <tag>}"
    exit 0
    ;;
esac

root="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$root"

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
