#!/bin/bash
#
# Rebuilds javascript/codemirror6.min.js from entry.js.
# Run only when upgrading CodeMirror - the output is committed to SVN.
#
# npm install deliberately happens OUTSIDE the working copy: this tree is rsynced
# to the live aadev server with --delete and has no exclude for node_modules, so a
# local install would ship ~100 MB to production and then be deleted again.
#
set -euo pipefail

src="$(cd "$(dirname "$0")" && pwd)"
out="$src/../../javascript/codemirror6.min.js"
work="$(mktemp -d -t aa-cm6-build.XXXXXX)"
trap 'rm -rf "$work"' EXIT

cp "$src/package.json" "$src/entry.js" "$work/"
cd "$work"

echo "installing pinned CodeMirror packages in $work ..."
npm install --no-audit --no-fund --silent

mkdir -p "$(dirname "$out")"
npx --no-install esbuild entry.js \
    --bundle \
    --format=esm \
    --minify \
    --target=es2020 \
    --legal-comments=inline \
    --outfile="$out"

out="$(cd "$(dirname "$out")" && pwd)/$(basename "$out")"
printf '\nbuilt:  %s (%s bytes)\n' "$out" "$(wc -c < "$out")"
printf 'SRI:    sha256-%s\n' "$(openssl dgst -sha256 -binary "$out" | openssl base64 -A)"

# esbuild resolves everything unless --external is given, so a surviving import
# statement would mean the bundle is not self-contained and the CDN version-skew
# problem is back. Match the statement form - a bare `from"` also occurs inside the
# bundled JavaScript grammar as a keyword string.
imports="$(grep -cE '(^|[;}])import[[:space:]]*[{*"]' "$out" || true)"
if [ "$imports" != "0" ]; then
    printf 'ERROR: %s unbundled import statement(s) remain\n' "$imports" >&2
    exit 1
fi
printf 'check:  self-contained, %s bytes gzipped\n' "$(gzip -c "$out" | wc -c)"
