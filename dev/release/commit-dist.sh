#!/usr/bin/env bash
# Replace the working tree of a `dist` branch checkout with a freshly built dist tree and commit it:
#   dev/release/commit-dist.sh <built-dist-dir> <dist-branch-checkout> "<commit message>"
# The message comes from commit-message.sh, which needs the previous dist commit's message.
# Prints changed=true|false into $GITHUB_OUTPUT when running under GitHub Actions.
set -euo pipefail
built=$(realpath "$1"); wc=$(realpath "$2"); msg=${3:?commit message}
# per-install keys and configs are never published, whatever tree is passed in
leak=$("$(dirname "$0")/per-install-files.sh" "$built")   # no || true: a missing helper must stop the run
[ -z "$leak" ] || { echo "commit-dist: per-install files in the built tree, refusing:" >&2; echo "$leak" >&2; exit 2; }
cd "$wc"
git rev-parse --verify -q HEAD >/dev/null || git checkout -q --orphan dist   # very first build: start the branch
rsync -a --delete --exclude=.git "$built/" "$wc/"
git add -A
# release.php changes with every main commit; a dist commit is only worth it when runtime files changed
if [ -z "$(git diff --cached --name-only -- . ':(exclude)include/release.php')" ]; then
    git reset -q -- include/release.php 2>/dev/null && git checkout -q -- include/release.php 2>/dev/null || true
    echo "commit-dist: no runtime changes"; echo "changed=false" >> "${GITHUB_OUTPUT:-/dev/null}"; exit 0
fi
git -c user.name="ActionApps CI" -c user.email="ci@actionapps.org" commit -q -m "$msg"
echo "changed=true" >> "${GITHUB_OUTPUT:-/dev/null}"
echo "commit-dist: $(git rev-parse --short HEAD) $(git log -1 --format=%s)"
