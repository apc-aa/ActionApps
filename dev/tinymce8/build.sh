#!/bin/bash
#
# Rebuilds javascript/tinymce8.min.js and javascript/tinymce8/ (language packs,
# licenses) from entry.js. Run only when upgrading TinyMCE - the output is committed.
#
# npm install deliberately happens OUTSIDE the working copy: the tree is rsynced to
# the live aadev server with --delete and has no exclude for node_modules.
#
set -euo pipefail

src="$(cd "$(dirname "$0")" && pwd)"
js="$(cd "$src/../../javascript" && pwd)"
out="$js/tinymce8.min.js"
work="$(mktemp -d -t aa-tinymce8-build.XXXXXX)"
trap 'rm -rf "$work"' EXIT

cp "$src/package.json" "$src/entry.js" "$work/"
cd "$work"
echo "installing pinned TinyMCE packages in $work ..."
npm install --no-audit --no-fund --silent

npx --no-install esbuild entry.js \
    --bundle \
    --format=esm \
    --minify \
    --target=es2020 \
    --legal-comments=inline \
    --outfile="$out"

# language packs of the languages AA has a UI in (include/lang/); TinyMCE loads one on demand
rm -rf "$js/tinymce8"
mkdir -p "$js/tinymce8/langs"
for lang in bg-BG cs de es fr-FR hr hu-HU ja pl pt-PT ro sk uk vi; do
    cp "node_modules/tinymce-i18n/langs8/$lang.js" "$js/tinymce8/langs/"
done
cp node_modules/tinymce/license.md "$js/tinymce8/LICENSE.md"
cp node_modules/tinymce/notices.txt "$js/tinymce8/NOTICES.txt"
cp node_modules/tinymce-i18n/LICENSE.md "$js/tinymce8/LICENSE-i18n.md"

printf '\nbuilt:  %s (%s bytes)\n' "$out" "$(wc -c < "$out")"
printf 'SRI:    sha256-%s\n' "$(openssl dgst -sha256 -binary "$out" | openssl base64 -A)"

# a surviving import statement would mean the bundle is not self-contained
imports="$(grep -cE '(^|[;}])import[[:space:]]*[{*"]' "$out" || true)"
if [ "$imports" != "0" ]; then
    printf 'ERROR: %s unbundled import statement(s) remain\n' "$imports" >&2
    exit 1
fi
printf 'check:  self-contained, %s bytes gzipped\n' "$(gzip -c "$out" | wc -c)"
