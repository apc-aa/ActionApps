#!/usr/bin/env bash
# Print the per-install files (configs, keys, own code and their backup copies) found in a built tree:
#   dev/release/per-install-files.sh <tree>
# commit-dist.sh and sync-svn.sh refuse to publish a tree for which it prints anything.
t=$1
{
    find "$t/config/certs" -mindepth 1 ! -name README
    find "$t/include" -maxdepth 1 \( \( -name 'config*' ! -name config.php.default \) -o -name '.config*' -o -name 'usr_aliasfnc.php*' -o -name custom \)
    find "$t/misc/datovka" -maxdepth 1 -name 'datovka_conf.php*' ! -name datovka_conf.php.default
} 2>/dev/null
exit 0
