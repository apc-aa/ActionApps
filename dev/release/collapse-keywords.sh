#!/usr/bin/env bash
# Collapse expanded SVN keywords ($Id: … $ -> $Id$) in every text file under a tree.
# Same regex the git history rewrite used, so an SVN export compares equal to the git tree.
set -euo pipefail
root=${1:-.}
{ grep -rIlZ --exclude-dir=.git --exclude-dir=.svn -E '\$(Id|Revision|Rev|Date|Author|HeadURL|Header|LastChangedBy|LastChangedDate|LastChangedRevision|URL):[^$]*\$' "$root" || true; } \
  | xargs -0 -r perl -pi -e 's/\$(Id|Revision|Rev|Date|Author|HeadURL|Header|LastChangedBy|LastChangedDate|LastChangedRevision|URL)(:[^\$\n]*)?\$/\$$1\$/g'
