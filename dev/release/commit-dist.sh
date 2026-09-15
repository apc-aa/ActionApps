#!/usr/bin/env bash
# Replace the working tree of a `dist` branch checkout with a freshly built dist tree and commit it:
#   dev/release/commit-dist.sh <built-dist-dir> <dist-branch-checkout> <source-sha> "<source subject>"
# Prints changed=true|false into $GITHUB_OUTPUT when running under GitHub Actions.
set -euo pipefail
built=$(realpath "$1"); wc=$(realpath "$2"); sha=$3; subject=$4
cd "$wc"
git rev-parse --verify -q HEAD >/dev/null || git checkout -q --orphan dist   # very first build: start the branch
rsync -a --delete --exclude=.git "$built/" "$wc/"
git add -A
# release.php changes with every main commit; a dist commit is only worth it when runtime files changed
if [ -z "$(git diff --cached --name-only -- . ':(exclude)include/release.php')" ]; then
    git reset -q -- include/release.php 2>/dev/null && git checkout -q -- include/release.php 2>/dev/null || true
    echo "commit-dist: no runtime changes"; echo "changed=false" >> "${GITHUB_OUTPUT:-/dev/null}"; exit 0
fi
git -c user.name="ActionApps CI" -c user.email="ci@actionapps.org" commit -q \
    -m "dist: main@${sha:0:7} $subject" -m "Built by dev/release/build-dist.sh from commit $sha."
echo "changed=true" >> "${GITHUB_OUTPUT:-/dev/null}"
echo "commit-dist: $(git rev-parse --short HEAD) $(git log -1 --format=%s)"
