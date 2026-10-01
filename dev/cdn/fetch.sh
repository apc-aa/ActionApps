#!/bin/bash
# cdn/ is a local mirror of files from jsDelivr, at the same path as on https://cdn.jsdelivr.net/
# (cdn/npm/<package>@<version>/..., cdn/gh/<user>/<repo>@<version>/...). AA serves them itself
# (AA_Requires::mirror()), so a site does not open a connection to the CDN and the CDN does not see
# its visitors. SHA256SUMS next to this script pins every file, like a lock file.
#
#   dev/cdn/fetch.sh                  check every file against SHA256SUMS
#   dev/cdn/fetch.sh <path>...        download these paths (new version, font, image...) and record them
#   dev/cdn/fetch.sh --check-remote   compare every recorded file with what jsDelivr serves now
#
# A new version is a new directory: fetch its files, point mirror() at it, delete the old directory
# and its lines in SHA256SUMS. Take .min.js / .min.css - jsDelivr minifies any file when .min goes
# before the extension. Its minified file starts with a ~300 B comment, so keep the original when
# that one is smaller (sortable.css: mostly base64 images). jsDelivr may regenerate such a file -
# --check-remote then reports DIFFERS while our pinned copy stays as it was.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
sums="$root/dev/cdn/SHA256SUMS"
cdn='https://cdn.jsdelivr.net'
mkdir -p "$root/cdn"
cd "$root/cdn"

fetch() {   # <path> <target file>
    mkdir -p "$(dirname "$2")"
    curl -fsSL --retry 2 -o "$2" "$cdn/$1"
}

if [ "${1:-}" = '--check-remote' ]; then
    tmp=$(mktemp)
    trap 'rm -f "$tmp"' EXIT
    while read -r sum path; do
        if ! fetch "$path" "$tmp"; then
            echo "FAILED   $path"
        elif [ "$(sha256sum < "$tmp" | cut -d' ' -f1)" = "$sum" ]; then
            echo "same     $path"
        else
            echo "DIFFERS  $path"
        fi
    done < "$sums"
    exit 0
fi

for path in "$@"; do
    case "$path" in
        *..*|*' '*) ;;
        npm/?*@?*/?*|gh/?*/?*@?*/?*) fetch "$path" "$path"
            { awk -v p="$path" '$2 != p' "$sums" 2>/dev/null || true; sha256sum "$path"; } | LC_ALL=C sort -k2 > "$sums.new"
            mv "$sums.new" "$sums"
            continue ;;
    esac
    echo "not a jsDelivr path npm/<package>@<version>/<file> or gh/<user>/<repo>@<version>/<file>: $path" >&2
    exit 1
done

sha256sum --quiet -c "$sums"

# a file nobody recorded would never be checked
comm -23 <(find . -type f -printf '%P\n' | LC_ALL=C sort) <(cut -d' ' -f3- "$sums" | LC_ALL=C sort) | sed 's/^/not in SHA256SUMS: /'
