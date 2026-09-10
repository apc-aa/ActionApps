#!/bin/bash
#
# Replaces the INCLUDE_AAJSLIB_COMMON marker in one aajslib-*-src.js with the
# contents of aajslib-common.js, and writes the result to its runtime location.
#
#   dev/aajslib/aajslib-legacy-src.js  ->  javascript/aajslib-legacy.js
#
# Usage:  merge_common_js.sh aajslib-jquery-src.js       (bare name or path)
#
# This only produces the readable build. What actually loads is the minified
# file, so follow up with:
#
#   uglifyjs javascript/<name>.js -o javascript/<name>.min.js
#
# and bump AA_JS_VERSION in include/constants.php.
#
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
common_js="$here/aajslib-common.js"
out_dir="$(cd "$here/../../javascript" && pwd)"

input_js="${1:-}"
[ -n "$input_js" ] || { echo "usage: $(basename "$0") <name>-src.js" >&2; exit 1; }
# accept a bare filename as well as a path, from any working directory
[ -f "$input_js" ] || input_js="$here/$(basename "$input_js")"
[ -f "$input_js" ] || { echo "no such input: $input_js" >&2; exit 1; }

filename="$(basename "$input_js")"
output_js="$out_dir/${filename/-src/}"

sed "\#INCLUDE_AAJSLIB_COMMON#{
    r $common_js
    d
}" "$input_js" > "$output_js"

echo "built: $output_js"
