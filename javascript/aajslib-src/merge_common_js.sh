#!/bin/bash
# merge.sh: Replaces a marker line in a JS file with the content of a common file.
# Usage: merge.sh input.js
# The marker line to be replaced is: //INCLUDE(aajslib-common.js)

# Input JS file
input_js="$1"

# Define the path to the common script file.
common_js="./aajslib-common.js"

# Determine the output filename:
# Example: aajslib-legacy-src.js becomes aajslib-legacy.js stored one directory up.

# Extract the filename from the input path.
filename=$(basename "$input_js")
# Remove "-src" from the filename.
output_filename="${filename/-src/}"
# Determine the parent directory of the input file.
output_dir="$(dirname "$input_js")/.."
# Combine to form the full output path.
output_js="$output_dir/$output_filename"

# Use sed to replace the marker line with the content of the common file.
# We use '#' as the delimiter to avoid escaping '/' in the pattern.
sed "\#INCLUDE_AAJSLIB_COMMON#{
    r $common_js
    d
}" "$input_js" > "$output_js"
