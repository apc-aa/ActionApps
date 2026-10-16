# Installing ActionApps

This guide installs ActionApps (AA) from git on a typical Linux web host with shell (SSH) access.
To update an existing installation, see [UPDATE.md](UPDATE.md).

The examples assume the site `https://example.org/` with the document root `/var/www/example.org/`,
and AA in the subdirectory `apc-aa/`. Replace these with your own values.

## Requirements

- **PHP 8.2 or newer**, with these extensions: `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `iconv`,
  `intl`, `mbstring`, `pdo_mysql`, `simplexml`, `sodium`, `xml`, `xsl`, `zlib`.
  Any PHP setup works: PHP-FPM, FastCGI or mod_php.
- **MariaDB 10.0.2 or MySQL 5.7.5** or newer (one connection holds several named locks), and
  one empty database for AA.
- **A web server** that runs PHP: Apache 2.4 or nginx.
- **git 2.28 or newer** and shell access to the server. Composer is not needed, because the
  release branch already includes `vendor/`.

Check PHP with `php -v` and `php -m`. The command-line PHP can differ from the web server's PHP,
so if in doubt, check the web server's PHP with a `phpinfo()` page.

## 1. Get the code

```sh
cd /var/www/example.org
git clone --depth 1 --single-branch --branch dist https://github.com/apc-aa/ActionApps.git apc-aa
```

The `dist` branch holds only the files a running site needs. It is rebuilt after every change in
the source branch `main`.

## 2. Create the database

Create the database with your hosting control panel, or in the MariaDB/MySQL shell:

```sql
CREATE DATABASE aadb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aadbuser'@'localhost' IDENTIFIED BY 'choose-a-password';
GRANT ALL PRIVILEGES ON aadb.* TO 'aadbuser'@'localhost';
```

AA creates its tables with the database's default character set, so the database must be `utf8mb4`.
You can choose a collation for your language (for example `utf8mb4_czech_ci`), but use the same
collation for `DB_COLLATION_CONNECTION` in step 3.

## 3. Configure

```sh
cd /var/www/example.org/apc-aa
cp include/config.php.default include/config.php
openssl rand -hex 16          # prints a new AA_ID
```

Edit `include/config.php`. The comments in the file explain each option. You must set these:

| Constant | Example | Notes |
|---|---|---|
| `AA_SITE_PATH` | `/var/www/example.org/` | Document root, with a trailing slash. |
| `AA_BASE_DIR` | `apc-aa/` | AA directory under the document root. |
| `AA_HTTP_DOMAIN` | `https://example.org/` | Trailing slash required. |
| `AA_ID` | output of `openssl rand -hex 16` | 32 hex characters. Set it **before** step 5; it cannot be changed later. |
| `ORG_NAME` | `Example Org` | Must be unique if you share content with other AA installations. |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | `localhost`, `aadb`, … | From step 2. |
| `DB_COLLATION_CONNECTION` | `utf8mb4_unicode_ci` | Same collation as the database. |
| `DEFAULT_LANG_INCLUDE` | `en-utf8_news_lang.php` | Admin interface language; files are in `include/lang/`. |
| `ERROR_REPORTING_EMAIL`, `ALERTS_EMAIL` | `webmaster@example.org` | |

If your server cannot send mail with PHP's `mail()`, set `AA_MAIL_TRANSPORT` to `smtp` and fill in the
`AA_SMTP_*` constants.

Uncomment `define('AA_LOG_PHP_FATALS', true);` too, so that PHP fatal errors appear in
**AA → State Check** and not only in the web server's error log.

`config.php` contains the database password. Make it readable only by you and the web server, for
example with `chmod 640 include/config.php` and the web server's group.

## 4. Create the upload directory

AA stores uploaded files and generated thumbnails in `IMG_UPLOAD_PATH`. By default this is `img_upload/`
in the document root, outside the AA directory:

```sh
mkdir /var/www/example.org/img_upload
chgrp www-data /var/www/example.org/img_upload    # the group your PHP runs as
chmod 2775 /var/www/example.org/img_upload
```

Anyone who can upload a file here must not be able to run it as PHP. See
[Web server configuration](#web-server-configuration) below.

## 5. Create the database tables

The database script is protected by an access code, which you set in the script itself:

1. In `service/sql_update.php`, set `const ACCESS_CODE = '…';` to a random string of at least
   10 characters.
2. Open `https://example.org/apc-aa/service/sql_update.php` and enter the code.
3. Check **Write to database** and click **Install / Update**. Without the check box the script
   only reports what it would do. On an empty database this takes a few seconds and lists every
   table and template it creates.
4. Click **Test**. Every row should say `OK`.
5. Remove the code again, so that `git pull` keeps working and nobody can guess the code later:

   ```sh
   git checkout -- service/sql_update.php
   ```

## 6. Create the superadmin account

Open `https://example.org/apc-aa/admin/setup.php`, click **Init** and fill in the form. Once a
superadmin exists, the page refuses to create another one.

Log in at `https://example.org/apc-aa/admin/`.

## 7. Set up cron

AA runs its background tasks (e-mail alerts, feeds, cache cleanup, scheduled publishing) from a task
queue. Run `cron.php` every minute:

```cron
* * * * * php /var/www/example.org/apc-aa/cron.php > /dev/null 2>&1
```

If your host only lets you call URLs from cron, use
`curl -fsS -o /dev/null https://example.org/apc-aa/cron.php` instead.

Without cron, AA runs the queue after normal page requests, at most every 2 minutes. This is enough
for testing, but on a quiet site the tasks run late. Once cron works, you can switch this off with
`define('DISABLE_CRON_FALLBACK', true);` in `config.php`.

## 8. Check the installation

In the admin interface, open **AA → State Check**. It checks the database, the task queue, cron,
mail and other parts that can fail without any visible error. Right after installation it reports
that the task queue has never run. This goes away after the first cron run, or after the first page
view if you have no cron yet.

## Web server configuration

- **Hide version-control and private files.** `.git/` and `include/` must not be readable from the
  web.
- **Do not run PHP in the upload directory.**
- **Route `/api/`** to `apc-aa/api/index.php`. On Apache this needs `AllowOverride FileInfo` for the
  `.htaccess` file that AA ships.

Apache (in the virtual host):

```apache
<DirectoryMatch "/\.(git|svn)">
    Require all denied
</DirectoryMatch>
<Directory /var/www/example.org/apc-aa/include>
    Require all denied
</Directory>
<Directory /var/www/example.org/img_upload>
    php_admin_flag engine off                    # mod_php
    <FilesMatch "\.(php|phar|phtml)$">           # PHP-FPM / FastCGI
        Require all denied
    </FilesMatch>
</Directory>
```

nginx:

```nginx
location ~ /\.(git|svn)          { deny all; }
location ^~ /apc-aa/include/     { deny all; }
location ^~ /img_upload/         { location ~ \.(php|phar|phtml)$ { deny all; } }
location ~ ^/apc-aa/api/(.*)$   { try_files $uri /apc-aa/api/index.php?_path=$1&$args; }
```

## Installing from Subversion (deprecated)

The SVN trunk at SourceForge (`https://svn.code.sf.net/p/apc-aa/svn/trunk`) is a read-only mirror of
the `dist` branch. Existing SVN installations can keep using `svn update`, but do not use SVN for new
installations. To move an SVN installation to git, see [UPDATE.md](UPDATE.md#moving-from-svn-to-git).

## Developers

Clone the `main` branch (sources, tests, build tools) and run `composer install`.
`dev/release/README.md` describes how `dist` is built.
