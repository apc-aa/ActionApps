# Release tooling

`main` holds the sources. Servers run the **`dist`** branch (or the SVN trunk, which mirrors it):
runtime files only, `vendor/` built with `composer install --no-dev` and pruned by `vendor-prune.txt`,
everything in `dist-exclude.txt` left out, plus `include/release.php` (which `main` commit the tree was
built from, shown by `aa_version()`).

## What runs automatically
`.github/workflows/ci.yml`, on every push to `main`: `composer check` → `build-dist.sh` → `commit-dist.sh`
(push to `dist`) → `sync-svn.sh` (SourceForge SVN trunk; needs the `SF_SVN_SSH_KEY` repository secret,
the job is skipped without it). A push that changes no runtime file produces no dist commit.
Both commit messages (`commit-message.sh`) list every `main` commit since the previous dist build, or since
the previous SVN commit for SVN, so the SourceForge commit mail shows the whole push.

## Doing it by hand (no CI, no AI)
```bash
git clone https://github.com/apc-aa/ActionApps.git src            # or any checkout of main
src/dev/release/build-dist.sh src /tmp/dist                        # needs php + composer + rsync
git clone --branch dist --single-branch https://github.com/apc-aa/ActionApps.git distwc
msg=$(src/dev/release/commit-message.sh src HEAD "$(git -C distwc log -1 --format=%B)")
src/dev/release/commit-dist.sh /tmp/dist distwc "$msg"
git -C distwc push origin HEAD:dist
svn checkout svn+ssh://honzam@svn.code.sf.net/p/apc-aa/svn/trunk svnwc  # once; keep it around
svn update -q svnwc && msg=$(src/dev/release/commit-message.sh src HEAD "$(svn log -l 1 svnwc)")
DRY_RUN=1 src/dev/release/sync-svn.sh /tmp/dist svnwc "$msg"   # review
src/dev/release/sync-svn.sh /tmp/dist svnwc "$msg"
```

## Fixes made on a server (staging /aa-dev/ runs a `dist` checkout)
```bash
git switch -c fix/short-name && git commit -am "Fix: …" && git push -u origin fix/short-name   # server
git fetch && git cherry-pick origin/fix/short-name && git push                                 # workstation, on main
git switch dist && git pull --ff-only && git branch -D fix/short-name                          # server, after CI built dist
```
`cherry-pick` is the right tool because `dist` and `main` do not share history; the file paths do.

## Per-install files
`include/config.php` (and the legacy `include/config.php3`) are never part of `main` or `dist`; each
installation creates them from `include/config.php.default` (see INSTALL.md) and dist's `.gitignore` hides
them from `git status`. `sync-svn.sh` deliberately leaves the `include/config.php` shim that SVN trunk has
always shipped in place, because SVN installations `require_once` it.
The same holds for every per-install path of `.gitignore`: `dist-exclude.txt` leaves each one out, so a build
from a working tree ships none of them (`tests/Unit/Release/DistExcludeTest.php` keeps the two lists in step),
and `commit-dist.sh` / `sync-svn.sh` refuse a tree in which `per-install-files.sh` finds a config, a key,
`include/custom/` or a backup copy of them.

## Keeping the exclude lists honest
`collapse-keywords.sh <tree>` turns expanded SVN keywords (`$Id: … $`) into their bare form so an
`svn export` can be compared with a git tree (`diff -r`). Used by the migration and by nothing at runtime.
