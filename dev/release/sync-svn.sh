#!/usr/bin/env bash
# Mirror a built dist tree into an SVN working copy of trunk and commit the difference:
#   dev/release/sync-svn.sh <built-dist-dir> <svn-trunk-wc> "<commit message>"
# Needs svn + rsync; for svn+ssh set SVN_SSH (e.g. SVN_SSH="ssh -i ~/.ssh/id_ed25519_sf").
# DRY_RUN=1 stops before the commit and prints `svn status`. SVN is a one-way mirror: anything committed
# to trunk by hand is overwritten by the next run.
# include/config.php is left alone: dist does not carry it, but SVN installations require_once the shim
# that trunk has always shipped, and deleting it from trunk would take every `svn update`d site down.
set -euo pipefail
built=$(realpath "$1"); wc=$(realpath "$2"); msg=${3:?commit message}
[ -d "$wc/.svn" ] || { echo "sync-svn: not an svn working copy: $wc" >&2; exit 2; }
cd "$wc"
svn update -q --non-interactive
rsync -a --delete --exclude=.svn --exclude=.git --exclude=/include/config.php "$built/" "$wc/"
svn add -q --force --no-ignore --depth infinity .
# rsync removed the files; tell svn (parents first, children are then already gone)
svn status | { grep '^!' || true; } | cut -c9- | sort | while IFS= read -r p; do svn rm -q --force -- "$p" 2>/dev/null || true; done
if svn status | grep -q '^!'; then echo "sync-svn: unresolved missing items:" >&2; svn status | grep '^!' >&2; exit 1; fi
svn propdel -q -R svn:keywords . 2>/dev/null || true   # git-era files carry a bare $Id$; stop svn expanding it
if [ -z "$(svn status -q)" ]; then echo "sync-svn: trunk already up to date"; exit 0; fi
echo "sync-svn: $(svn status -q | wc -l) changed paths"
if [ "${DRY_RUN:-0}" = 1 ]; then svn status -q; exit 0; fi
svn commit -q --non-interactive -m "$msg" .
echo "sync-svn: committed r$(svn info --show-item revision .)"
