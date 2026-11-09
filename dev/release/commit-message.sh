#!/usr/bin/env bash
# Commit message of a dist build: the main commit it was built from plus every main commit since the
# previous build, so the dist log and the SVN commit mail show the whole push, not only its last commit:
#   dev/release/commit-message.sh <main-checkout> <sha | message of the dist commit> "<message of the previous build>"
# A build is known by its `Built by … from commit <sha>` line (older builds: a `dist: main@<sha>` or
# `dist: <sha>` subject). When the previous build is not an ancestor in the checkout (first build,
# rewritten history, shallow clone), only the new commit is listed.
set -euo pipefail
src=${1:?main checkout}; this=${2:?sha or dist commit message}; prev_msg=${3:-}
max=100   # a sync stopped for weeks would mail thousands of lines; the newest are the ones that matter

# the commit a build message names, empty when it names none
built_from() {
    local re_line=$'(^|\n)Built by [^\n]* from commit ([0-9a-f]{40})\\.' re_subject=$'(^|\n)dist: (main@)?([0-9a-f]{7,40})([^0-9a-f]|$)'
    if [[ $1 =~ $re_line ]]; then
        printf '%s' "${BASH_REMATCH[2]}"
    elif [[ $1 =~ $re_subject ]]; then
        printf '%s' "${BASH_REMATCH[3]}"
    fi
}

ref=$(built_from "$this"); ref=${ref:-$this}
sha=$(git -C "$src" rev-parse --verify -q "$ref^{commit}") || { echo "commit-message: unknown commit $ref" >&2; exit 2; }
prev=$(built_from "$prev_msg")
[ -z "$prev" ] || prev=$(git -C "$src" rev-parse --verify -q "$prev^{commit}") || prev=
if [ -n "$prev" ] && [ "$prev" != "$sha" ] && git -C "$src" merge-base --is-ancestor "$prev" "$sha"; then
    count=$(git -C "$src" rev-list --count "$prev..$sha")
else
    count=1
fi

subject="dist: main@${sha:0:7} $(git -C "$src" log -1 --format=%s "$sha")"
[ "$count" -gt 1 ] && subject+=" (+$((count - 1)) more)"
printf '%s\n' "${subject//$'\r'/}"   # svn refuses a log message with a CR in it
if [ "$count" -gt 1 ]; then
    printf '\n%s commits of main since the previous build (%s):\n' "$count" "${prev:0:7}"
    [ "$count" -le "$max" ] || printf -- '- ... %s older: git log %s..%s\n' "$((count - max))" "${prev:0:7}" "${sha:0:7}"
    list=$(git -C "$src" log -n "$max" --format='- %h %s' "$prev..$sha" | tac)
    printf '%s\n' "${list//$'\r'/}"
fi
printf '\nBuilt by dev/release/build-dist.sh from commit %s.\n' "$sha"
