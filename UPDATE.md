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
The most common case is an access code left in `service/sql_update.php`.

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

## Rolling back

`git pull` stores the previous version as `ORIG_HEAD`:

```sh
git reset --hard ORIG_HEAD
```

If the database was already updated, restore the backup from step 1 as well. After a later pull
`ORIG_HEAD` points elsewhere; then find the commit you need with `git reflog`.

## Subversion installations (deprecated)

The SVN trunk is a read-only mirror of the git `dist` branch, so `svn update` still delivers the same
code. After `svn update`, continue with step 3 above, and look for new options in
`include/config.php.default`.

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
