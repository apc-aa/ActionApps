# Updating ActionApps

This guide updates an ActionApps installation made from git as described in [INSTALL.md](INSTALL.md).
The examples use the same paths: AA in `/var/www/example.org/apc-aa/`, served at
`https://example.org/apc-aa/`.

## 1. Back up

```sh
mysqldump --single-transaction -u aadbuser -p aadb > aadb-$(date +%F).sql
```

Your files (`include/config.php`, the upload directory) are not touched by the update, but include
them in your regular backups.

## 2. Update the code

```sh
cd /var/www/example.org/apc-aa
git pull --ff-only
```

If git refuses with *"Your local changes … would be overwritten"*, a file shipped with AA was edited
on the server. `git diff` shows the edit. Keep a copy of it if you need it, then run
`git checkout -- <file>` and pull again.
The most common case is an access code left in `service/sql_update.php`. The exception is
`misc/datovka/datovka_conf.php`: never check it out, see [Data-box settings](#data-box-settings).

## 3. Update the database

Log in as superadmin and open **AA → Optimize**. Search for `sql_update` in the **Types** column,
select all rows and run **Test**. Then run **Repair** on the rows that report a problem.

If you cannot log in, for example because the update needs a new column first, use
`service/sql_update.php` as described in [INSTALL.md, step 5](INSTALL.md#5-create-the-database-tables).

Then open **AA → State Check** and look for new warnings.

## 4. Check for new configuration options

New options appear in `include/config.php.default`. See what changed with this update:

```sh
git diff ORIG_HEAD HEAD -- include/config.php.default
```

Copy the options you want into `include/config.php`. Options you leave out keep their defaults.

## Data-box settings

Since October 2026, `misc/datovka/datovka_conf.php` (the settings of `{datovka}`) belongs to the
installation, like `include/config.php`: git ignores it, and AA ships the template
`misc/datovka/datovka_conf.php.default` instead. The addresses of ISDS are now part of AA; from your file
AA reads only `instances`, `cert` and `atsId`. Each URL in `instances` must start with `https://` and end
with `/`, or AA leaves that entry out.

- **You never edited the file:** the update deletes it and nothing is lost. To use `{datovka}`, copy the
  template to `datovka_conf.php` and fill it in.
- **You edited it:** git stops the pull and changes nothing. `git diff misc/datovka/datovka_conf.php`
  shows your edit; AA no longer reads the `url_*` and `wsdl_*` lines, and a relative `cert` now starts
  in `config/certs/` (a path with `AA_BASE_PATH` or a full path stays as it is). Move the file out of
  the web tree, pull, and move it back:

  ```sh
  mv misc/datovka/datovka_conf.php ~/datovka_conf.keep
  git pull --ff-only
  mv ~/datovka_conf.keep misc/datovka/datovka_conf.php
  ```

  `mv` keeps the owner, mode and ACL of the file; if you copy it instead, use `cp -a`, not `cp -p`.
  Never use `git checkout --`, `git stash` or `git pull --autostash` on this file: the first throws your
  settings away, the others can leave them in the template or in a stash.
- **Several trees over one database** (a staging tree next to production, say) serve the same sites
  and share one task queue, so they must read one file: in the other trees, make
  `misc/datovka/datovka_conf.php` a symbolic link to the file of the main tree.
- **Copies** of the file belong outside the web tree: the web server may send `datovka_conf.php.bak` or
  `datovka_conf.php~` to anyone as plain text.

## Rolling back

`git pull` stores the previous version as `ORIG_HEAD`:

```sh
git reset --hard ORIG_HEAD
```

If the database was already updated, restore the backup from step 1 as well. After a later pull
`ORIG_HEAD` points elsewhere; then find the commit you need with `git reflog`.

Going back to a version from before October 2026, with `git reset --hard` or `git checkout`,
overwrites your `misc/datovka/datovka_conf.php` with the shipped one. Move it out first and back after.

## Subversion installations (deprecated)

The SVN trunk is a read-only mirror of the git `dist` branch, so `svn update` still delivers the same
code. After `svn update`, continue with step 3 above, and look for new options in
`include/config.php.default`.

If you edited `misc/datovka/datovka_conf.php` (see [Data-box settings](#data-box-settings)), move it
out of the web tree before `svn update` and back after; `svn status` then lists it with `?`. Without
that, the update leaves it in a tree conflict. To resolve one: copy the file out, run
`svn revert misc/datovka/datovka_conf.php`, and copy it back. Do not use `svn resolve --accept working`:
the file would stay scheduled for addition (`A +`), and a later `svn revert -R .` deletes it.

### Moving from SVN to git

You can convert an SVN installation to git in place. Your `include/config.php` and
`include/config.php3` and the upload directory stay where they are.

```sh
cd /var/www/example.org/apc-aa
svn status | grep -v '^?'       # must print nothing: no local changes to AA files
git init -b dist
git remote add origin https://github.com/apc-aa/ActionApps.git
git fetch --depth 1 origin dist
git reset --hard origin/dist
git branch --set-upstream-to=origin/dist
git status                      # only your own files are listed as untracked
rm -rf .svn
```

From now on, update with `git pull --ff-only`. Make sure the web server denies `.git/` (see
[INSTALL.md](INSTALL.md#web-server-configuration)).
