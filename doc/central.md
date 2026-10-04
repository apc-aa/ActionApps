# ActionApps Central

Central is the part of AA that manages other AA installations from one place (`central/`), and the
app install/transfer that moves sites and slices between them. This page is the admin and developer
reference; the code is in `central/*.php`, `src/Central/**` and `src/Util/LoginToken.php`.

## The list of installations

`central/index.php` (superadmin only) lists the rows of the `central_conf` table - one row per remote
AA: `ORG_NAME`, `AA_HTTP_DOMAIN`, `AA_BASE_DIR` and a login token. The responder of a row is
`AA_HTTP_DOMAIN` + `AA_BASE_DIR` + `central/responder.php`. Rows live in three bins (1 = approved,
2 = hold, 3 = trash); **only approved rows** are offered by Synchronize and Install an app.

Columns and actions:

- **Auth** - "Get Token": the username and password of a superadmin of that AA. Central calls
  `Get_Jwt` there and stores the `admin` token in `AA_ADMIN_PWD` as `v1:` + AES-256-GCM
  (key `Secret::key('central-credential')`, the responder URL bound as additional data); the
  password is not stored. The token lives **24 hours** and is not renewed: Central is for one-off
  work - log in to the AAs you need, work, go; the next day Get Token again. The column shows
  "Authenticated until <time>" or "Expired" (the token's `exp`, read unverified). A remote AA that gives
  a longer token (an older one ignores `ttl`) is refused - update it. A token stored for another
  URL (an edited row) is not sent; any value other than `v1:` (a plain `jwt:` token or a password of
  older Central) is "not authenticated" and never sent - Get Token overwrites it. A row without a
  valid token answers nothing (the pages say why). The form
  sends no 2FA code, so a superadmin with two-factor login cannot get a row token this way.
- **Apps** - "Install an app from this AA..." opens `installapp.php?aa=<row id>` (approved bin only).
  The link carries the row id, never a URL.
- Bulk actions: SQL update test/update (`Do_Optimize_Type`), link check, Update DB structure and Field
  duplicates test/repair (`Do_Optimize`), moving between bins, emptying the trash. Actions run only
  when started from the Central page itself (a forged link or cross-site form is refused).
- `import_csv.php` imports `central_conf` rows from CSV.

A row should name the final `https://` address of the AA: the install page refuses `http://` and
redirects (below).

## Synchronize

`synchronize.php` -> `synchronize2.php` -> `synchronize3.php`, superadmin only. It compares slice
definitions of a **template** AA with another AA and copies chosen differences.

1. Choose the template AA and the AA to compare (both from the Central list).
2. Pair the slices by hand: each template slice gets a slice of the other AA, or "do not compare".
3. Central reads both definitions (`Get_Module_Defs`) and lists the differences table by table:
   `module`, `slice`, `field`, `view`, `email`, `constant_slice`, `constant`.

How rows are paired:

- `module`, `slice` - the one row of each slice.
- `field`, `constant` - by id.
- `view` - by `field3` (the name `{view.php?vid=<name>}` finds), else by `name`; case-sensitive, and
  a named view never pairs with a view that has only a name.
- `email` - by `description`.
- A view or e-mail without a name, or with a name repeated on either side, is **not offered** - it is
  only reported as an INFO row. View and e-mail numbers are never used to pair: each AA numbers its own.

Never compared: the module column, and the installation columns - `module`: owner, created_by,
created_at, slice_url, deleted, priority; `slice`: the same plus mlxctrl, vid, fileman_dir,
reading_password; `constant`: short_id.

What is pre-checked: `INSERT` ("Create new") and `UPDATE_ROW` (the whole row; labelled "Update", like
`UPDATE_VALUE`). **Not**
pre-checked: every `DELETE`, every single-value `UPDATE_VALUE`, an `INSERT` of a view or e-mail
(a renamed row shows as INSERT + DELETE and would add a copy on every run), and an `UPDATE_ROW` of
`module`/`slice` that renames the destination. "Synchronize" only queues the checked actions as user
tasks (`AA_Task_Sync`); they run when started in the Task Manager (`admin/se_taskmanager.php`), never
from cron. The target applies them with `Do_Synchronize` and accepts only the seven tables above; a new
view or e-mail gets its own number ("... inserted ... as N").

Synchronize writes into live slices. Review every checked box, and try it on a copy first.

## Install an app

An **app** is a site with the modules in its "uses modules" list, or a set of slices and Alerts
modules chosen without a site. Its constant groups go with it.

### Install vs transfer

| | Install | Transfer |
|---|---|---|
| What | a clean app: definitions only | a copy or move: definitions + items + the state of the source |
| Items, readers (hashes, 2FA) | no | yes, in batches; active bin or all bins |
| Owner | one of this AA's, chosen in the preview (below) | the source's |
| Reading password, domains | no | yes |
| Setting values | a superadmin's install: the source's values, else the defaults (below) | yes |
| Who | `PS_INSTALL_APP` (+ `PS_EDIT` on the exported modules) | superadmin on both AAs |

A move is a transfer followed by deleting the source by hand after checking the copy; Central never
deletes the source. Module ids, view and e-mail numbers and `short_id` are always new. Item ids are
kept when the source is another AA; in the same AA they are derived from the run (`string2id`).

### Source: this AA or another one

`installapp.php` ("Install an app...") always runs on the **target** AA, which pulls the app.
The Central menu (and so its "Install an app..." item) is shown to superadmins only; a user with
`PS_INSTALL_APP` below superadmin opens `central/installapp.php` by its URL.

- **This AA** - choose a site, or slices and Alerts modules (not both), the mode and the bins.
- **Another AA** (superadmins only) - pick a row of the Central list. Log in with the username,
  password and 2FA code of a **superadmin of the source**. The target calls `Get_Jwt` with
  `scope=apps`, `ttl=86400` and keeps only the resulting short token (below). Then choose the app from
  the modules the source lists (`Get_Modules`).

To copy an app into another AA, open "Install an app" there and choose this AA as the source.

A row of the Central list that names this same installation is detected (the source sends its
`AA_ID`): the copy then behaves as one in the same AA.

### Preview

The source sends the definitions package (`AppExport` locally, `Get_App part=definitions` remotely).
The target reads it (`Reader` -> `Planner::choose()` -> `Validator` + `SecurityLint` -> `Planner`) and
shows it as one form: every module with its name (editable) and, for a slice, whether it is installed
and "Into"; the owner of the new modules (one of this AA's or a new one, created by the write); every
settings field of the slices with the value of the app and a field for a new one; the findings.
"Check the changes" previews again; after a change the Install button waits for it. The run keeps the
choices (`ImportChoices`), the write plans with the run's and refuses a form of an older preview.
Nothing is written except the run record. The preview is kept for a day.

A slice left out takes along what names it structurally: the site's "uses" row, a constant group only
it used; an Alerts module stays, without its reader slice, the filter views and e-mails of that slice
(W `reader-left-out`); a group others use loses its owner (W `group-owner-left-out`). References in
texts stay and are W `module-outside`. A typed settings value goes through the S lint.

Findings levels:

| Level | Meaning |
|---|---|
| **I** | note |
| **W** | warning - the app installs |
| **E** | error - nothing can be written until it is fixed |
| **S** | security: code that reads files, calls URLs or carries passwords, a kept domain. Blocks nothing, but needs confirmation |

**S confirmation**: the write form then has a required checkbox "I have read the security findings
(S)...". Its value is an HMAC keyed by the session id + `AA_ID` over the user, the SHA-256 of the
package and the sorted S findings - valid only in this admin session, for this package, for this user.

A transfer preview also checks the readers: logins that collide with an AA user or a privileged
reader are E. From another AA it checks that none of the source item ids exists here
(`item-id-exists`, E) - e.g. an earlier transfer not yet deleted (items in the trash keep their ids).

**Only items matching.** In a transfer each slice may take only the items matching a condition,
written as in `{ids}` and views: `d-<field>-<operator>-<value>`, more conditions joined by AND
(`d-relation.......4-==-<item id>-status_code.....-==-1`). The source evaluates it as any AA search
(`QueryZids()`, one condition at a time) - all AA operators and field forms, at most 10 conditions and
1000 bytes. Refused (E `filter-invalid`, nothing transferred) is only what AA would silently read as
more items than written: a field the slice lacks, a value missing or unreadable, a syntax error, `<>`
with a phrase, `|`, `BITSET 0`; so is a condition the source did not answer about (an older AA cannot
filter). Items then shows matching / all; W `filter-matches-all` when the
condition leaves nothing out; W `relation-left-out` counts the relation values of the transferred
items naming an item of a filtered slice that stays behind (a lower bound of the later
`relation-dangling`). Only a superadmin of the source may filter (a count of what a condition selects
tells what the items hold). The run keeps the conditions after the write: every batch, the progress
page and the finish report count only the matching items. In a copy within the same AA the ids of the
items left out stay in the definitions (views, spots), as with the items of the bins left out.

### Write

"Install" / "Write the definitions and transfer the items" reads the definitions from the source
again and compares them with the preview (SHA-256 fingerprint, same-AA flag, the Into choice); any
change -> "preview again". All definitions are written in **one InnoDB transaction** (the import
refuses MyISAM tables; see Optimize "Convert database engine to InnoDB"). The run moves to its items
in the same transaction, so the definitions are never written twice. After the commit: an `APP_INST`
log row, permissions for the new modules, the constants and page caches. The report lists the new modules and
ids, the settings to fill in and the findings. An install is finished here.

### Batches, Continue / End, the finish report

A transfer continues on the progress page (`installapp.php?run=<id>`). Its script requests one batch
after another; each batch is one request and one transaction:

- the source cuts at most **500 items / 4 MB** after the cursor (`id > cursor ORDER BY id`); an item
  larger than that alone is E `item-too-big`;
- the batch travels as a package XML too, and goes through `Reader`, `Validator`, the target charset
  check, `ItemLint` and `ItemWriter` (field types from the target's `field` rows);
- the run is read `FOR UPDATE`; a request naming another position than the run's is **stale** and
  does nothing - a double click or a second tab cannot write a batch twice;
- a failure rolls the batch back and puts the run in `error` with a code and a path (never a raw DB
  message; details in the PHP error log). Codes: E finding codes (`ITEM_TOO_BIG`, ...),
  `SOURCE_LOGIN`, `SOURCE_UNREACHABLE`, `SOURCE_FAILED`, `SOURCE_DENIED`, `COLLECT_FAILED`.

Run states: `preview` -> `done` (an install, or no slice) or `preview` -> `items`; `items` <-> `error`;
`items` -> `done`; `items` | `error` -> `ended`. An error never finishes a run directly.

- **Continue** resumes from the stored position. After `SOURCE_LOGIN` (the token expired) it asks
  for the source login again and stores the new token.
- **End** keeps what is written and stops. To undo a transfer, delete the new modules as usual.
- Closing the tab only pauses; "Install an app" lists the user's unfinished transfers.

When the last slice is done, the target checks (`TransferCheck`) and stores the **finish report**:
per slice written / on the source / on this AA, and W findings `count-differs`,
`edited-during-transfer` (items the source changed after the start - the source is not frozen),
`relation-dangling` (relation values naming no item), `source-count-unknown`. It clears the page cache
of the new modules and the constants cache and erases the token. Uploaded files are not transferred:
copy them by hand, and switch the domain only after checking the copy.

### Into an existing slice

In the preview each package slice may go into a **new slice** or an **existing slice** that the user
may edit ("Into"). Nothing is pre-selected. Changing a choice disables the write button until
"Check the changes"; the write takes only the choices of the last check.

What the existing slice has stays: its module and slice rows, profiles, settings, and the state a
transfer would bring (I `slice-state-kept`). What it lacks is added:

- fields by id - missing ones added, existing ones untouched (W `field-differs`; in a transfer a
  different storage is E `field-type-differs`, a relation to another slice W `relation-target-differs`);
- views by `field3`/name, e-mails by description (as Synchronize), objects by class and name - an
  existing one is reused and the package's references go to it (I `view-exists`, ...); an unmatched
  one is added (W `view-unmatched`, ...); a view of the same name that differs in type or main format
  stays as it is (W `view-differs`);
- the package slice id maps to the existing slice, so relations, sets and alerts point there;
- in an install, the settings fields it lacks get the source's or the typed values, else their defaults.

E cases: no such slice or not editable, chosen twice (`target-slice`), a transfer into its own source
slice (`into-source`), Reader Management into another kind or back (`slice-type-differs`). A
transfer into a slice with unique fields (unique validation, SEO name, sequence) gets W `unique-field`.

### Setting defaults and "settings to fill in"

An install by a superadmin of the source (this AA, or another AA logged in as its superadmin - never the
app store) carries the source's values of the settings fields; the preview shows them as the values of
the app and they are written unless one is typed. Otherwise each **new** slice's settings fields (id starting `_`) get their
`input_default`, evaluated as for a new item - only when the result is the same whoever installs:
generators `txt`, `qte`, `now`, `never`, `dte`, `mul`, with the insert function none, `qte`, `dte`,
`num` or `boo`. Any other default (user, random, an AA expression) or an insert function that changes
the value (a password) is skipped with I `setting-default-skipped`. A transfer brings the source's
values instead; a slice going into an existing one keeps its own.

A value typed in the preview travels as a setting value of the package (`Planner::choose()`), in place of
the source's: it is linted (S), checked for the target charset and references like any other, and the
source's value it replaces is no longer checked; the source's translations of it stay. The value must pass
the field's `input_validate` where that judges the value alone (e-mail, URL, number, date, regexp, enum:
E `setting-invalid`); a number field takes a whole number (E `setting-not-number`), a `boo` field offers
Yes / No, a field whose insert function changes the value (a password) takes none (E
`setting-insert-func`) - it is filled in the slice later. A slice going into an existing one takes no
typed value for a field it has. The choices form posts UTF-8 whatever the admin charset. A published app
carries the typed values only, nothing of its source (E `items-published`). A setting that may hold a
password or a key (`SettingDefaults::secret()`, by its id) is shown masked, in the preview and in every
finding; a superadmin's install copies it unless another value is typed.

The preview and the report list "*N* settings to fill in" per slice (the settings fields left empty),
linked to the slice's Setting page once it exists.

### Owner of the new modules

An install gives every module it creates an owner (`module.owner`, and `slice.owner` of a slice) - the
module settings cannot be saved without one. The preview offers this AA's owners (`slice_owner`);
preselected is the owner most of the modules the user may edit have, else the first by name. Owners
whose id lost its trailing zero bytes (an old repair) are not offered. Or a new owner (name and
e-mail): the write creates it once, in the charset of the first module it creates - E `owner-invalid`
unless both fit that charset and `slice_owner` (80 bytes), `slice_owner` must be InnoDB. Like any
choice, a change waits for "Check the changes". The id is checked on the server: E
`owner-unknown` for an id of no owner, and for no owner while this AA has some; the writer checks it
again in its transaction. A slice going into an
existing one keeps that slice's owner; with no new module no owner is asked for. A transfer brings the
source's owner instead (found by name and e-mail, or created) and writes it to `slice.owner` too.
New modules get `slice_url` = `''`, not NULL.

## The package format

A package (`aa-app`) is one UTF-8 XML document:

```xml
<aa-app xmlns="https://actionapps.org/ns/aa-app" format="1.1">
  <meta>...</meta>                    <!-- name, version, source (url, aa-version, site, exported), contents, tables -->
  <site-module id="..."/> <slice-module id="..."/> <alerts-module id="..."/>
  <constant-group name="..."/>
  <signature/>                        <!-- reserved, not checked -->
</aa-app>
```

- A row of a table is an element named after the table, a column a child element; templates in CDATA.
  The wrapper of a module decides which tables it may contain.
- The package carries the **source ids**; the target maps them (new module ids, view and e-mail
  numbers, group names, collection ids) and rewrites references: long ids everywhere, view/e-mail
  numbers in known forms, constant groups structurally. What cannot be rewritten is a lint finding.
- Columns follow the AA version (`metabase_schema.php`): unknown columns are pruned (W); unknown
  elements are pruned only in a package of a newer minor format. Another major format is refused.
- Reading is guarded (`XmlGuard`): no DTD or entities, at most 8 MB, 100 000 elements, depth 32;
  then XSD validation and semantic checks.
- The XSD is generated by `AA\Central\Package\Schema::xsd()` into `src/Central/Package/aa-app-1.xsd`
  (a test keeps them equal).

**Format 1.1** adds (all optional; an install refuses them with E `transfer-only`):

- `meta/batch @after @next @finished` - an item batch: one `slice-module` with only `@id` and
  `item*`, the items with ids in (`after`, `next`];
- transfer parts of a module: `owner` (name, e-mail), `reading-password` (slice), `alternate-url`
  (site domains), `setting-values` (slice);
- items in a transfer carry 2FA secrets and `posted_by`/`edited_by` as text; never `short_id`.

`meta/publish` is `all` in a package of a published app (see App store) and absent otherwise; an older
package's `users` or `no` reads as not published. An installed copy is never published. A published
package with items, a batch or transfer parts is E `items-published`. An AA reading 1.0
prunes the 1.1 parts (W `unknown-element`) and refuses batches.

## Responder commands for Central

All go to `central/responder.php` as a POST field `request` = `serialize(AA_Request(command,
params))`; the answer is a serialized `AA_Response`. `Authorization: Bearer <token>` is accepted
**only** on this page; a token login lives one request (no session, no cookie).

**`Get_Jwt`** - POST fields `username`, `password`, optional `code2fa`; params `scope` (`admin`
default | `apps`) and `ttl` (seconds). Only a **superadmin** logged in by the password in this very
request gets a token - and the app store's public account, an `apps` token only. **Every token lives at most a day**, of either scope: without ttl a day, any ttl
is kept within 60 s .. 1 day. A token whose `iat` is more than a day (+ 5 min of clock skew) old is
refused whatever its `exp` says, so the year-long tokens of older AAs no longer work. Claims: `sub`, `iat`, `exp`, `pwd` (a keyed tag of the password), `scp`
(`apps` only; no claim = admin). HS256 with a key derived from `AA_SECRET`.

**`Get_Modules`** - param `types` (e.g. `['W']`, `['S','Alerts']`) -> `id => name` of the
non-deleted modules. Superadmin.

**`Get_Apps`** - only with a Bearer token -> a list of apps (`id`, `name`, `description`, `version`,
`published`, `slices`, `alerts`), sorted by name: the published sites, and for a superadmin's token also
the sites whose modules it may edit.

**`Get_App`** - only with a Bearer token. Params `part`, `mode` (`install` | `transfer`), `bins`
(`active` | `all`), `started` (unixtime of the run) and per part:

| part | params | answer | right |
|---|---|---|---|
| `definitions` | `modules` (a site id, or slices/Alerts) | `['package' => XML, 'now' => time, 'aa' => AA_ID]` | install: `PS_EDIT` on every module; transfer: superadmin |
| `batch` | `slice`, `after` | package XML | transfer, superadmin |
| `logins` | `slices` (Reader Management) | list of logins | transfer, superadmin |
| `counts` | `slices`, `edited_after` | `slice => count` | `PS_EDIT` |
| `item_ids` | `slice`, `after` | up to 5000 ids after the cursor | transfer, superadmin |
| `left_out` | `slices` | `slice => relation values naming items the filters leave out` | transfer, superadmin |

`filters` (`slice => condition`, the slices of the part only) narrows `batch`, `logins`, `counts`,
`item_ids` and `left_out` to the matching items; superadmin only, in any mode. Such an answer is
`['filters' => sha256 of the canonical JSON of the filters, 'answer' => ...]` - the target refuses an
answer without that echo ("update the source AA"): an older source would answer for all the items.

Error codes (`AA\Central\ResponderPolicy`, plus the responder's own):

| Code | Meaning |
|---|---|
| 101 | no permission - `isPerm()` runs first: no credentials, a wrong password or 2FA code, `Get_Jwt` of a non-superadmin, `Get_App` without a Bearer token |
| 102 | no request - e.g. `command=` in the POST body instead of the serialized `request` |
| 103 | unknown command (also what an older AA answers to a new command) |
| 104 / 105 / 106 | remote command in the URL / POST-only command by GET / request from another site |
| 13 | `Get_Jwt` of a superadmin logged in otherwise than by the password in this request (cookie, token), or whose password change AA cannot detect |
| 14 | Bearer token refused (older than a day, password changed, bad signature) - authenticate again |
| 15 | Central side only: no token stored for the row, nothing was asked |
| 16 | `Get_App`: no such app, or you may not export it (one answer for both) |
| 17 | `Get_App`: the export found errors; the answer lists up to 50 lines `code path ref` |
| 18 | `Get_App`: a parameter missing or of a wrong form |
| 19 | `Get_App`: the source failed - details in its PHP error log |
| 20 | the token's scope does not allow the command |
| 21 | `Get_Jwt`: `ttl` or `scope` of a wrong form |
| 22 | the app store's public account may not run the command |
| 23 | the app store is busy: too many requests of the public account (client, IPv6 /48, or all) - try again later; also when this AA does not log `APPSTORE` |

## Security model

**Who may do what**

| Action | Needs |
|---|---|
| Central list, Get Token, Synchronize, CSV import | superadmin |
| Install an app from this AA | `PS_INSTALL_APP` (letter `U`, superadmin by default) + `PS_EDIT` on the exported modules |
| Install from another AA | superadmin here and a superadmin login of the source |
| Transfer (items, readers, hashes, 2FA) | superadmin here and on the source |
| Publish a site in the app store | superadmin |
| Install from the app store | superadmin with `PS_INSTALL_APP` here; the store's public account |
| Batches, Continue, End | the user who started the run, a superadmin with `PS_INSTALL_APP` |

A package is code: its templates run with all rights of the target AA. The S lint and its
confirmation help the admin; the boundary is who may install.

**Tokens**

- `admin` (Central's own login to a row): a day, no renewal - a long token would let a compromise of
  one AA reach the others. Stored encrypted in `central_conf` (`v1:`, bound to the row's responder
  URL); anything else in the column is never sent. Anyone who holds it controls that AA
  (`Do_Optimize`, `Do_Synchronize`). Give each Central its own account.
- `apps` (another AA pulling an app): at most a day, runs only `Get_App`, `Get_Apps` and `Get_Modules`
  (else 20).
  The target keeps it only in the run, AES-GCM encrypted with a key derived from `AA_SECRET`, sealed
  with the responder URL it was issued for. It is erased when the run is done or ended, and when a run
  has not moved on for a day. The source password is never stored.
- **Revocation**: change the user's password on the AA that issued the token - every token of that
  user stops working (for LDAP users, any change of the entry). Deleting the user, or changing
  `AA_SECRET`, revokes the rest. A single token cannot be revoked.

**Hardened client rules** (`RemoteSource`, used by every Central request to another AA - the install
page, Get Token, Synchronize, Optimize, the Task Manager tasks): the URL comes
only from a Central row, never from the request (the row must be approved when you log in; the later
steps read it in any bin - the token is sealed with the URL it was issued for, so a changed row cannot
redirect it); `https://` only; redirects not followed
(a redirect is an error); `verify_peer` and peer name, no self-signed certificates; 120 s timeout;
the answer is capped while it is read (1 MB; 4 MB for a `Do_*` report; 16 MB for a package and for
the module definitions of Synchronize) and at 64 header lines; only an `AA_Response` of plain data is
unserialized (plus the `AA_Definition_Module*` objects of `Get_Module_Defs`). The token's claims are checked before it is kept: scope
`apps` and a lifetime of a day (an older source that ignores `scope`/`ttl` and gives its year-long
admin token is refused - update it). A row on `http://` or on an address that redirects gets no
request at all - keep the rows on their final `https://` address.

**What is logged**

- Source, `log` type `APP_EXPORT`: every transfer export - `transfer definitions <modules>`,
  `transfer batch <slice>`, `transfer logins <slices>` with `user=<uid>` and counts, never data. Install
  exports, counts, item ids and `Get_Modules` are not logged.
- Target, `APP_INST`: one row per new module (or one with `AA_ID`): app name, version, source URL.
- `JWT_DENY`: a refused Bearer token, once a day per client IP, with the reason.
- `SESSID_USE`: `Get_Sessionid` logins, once a day per IP and kind.
- `APPSTORE`: every request of the app store's public account, `command:client` (IPv4, or IPv6
  `/48 /64`) - the hourly limits count them.
- Failures of the install page and of `Get_App` go to the PHP error log; pages show codes only.

## App store

Any AA can be a store: it offers its **published** sites to every other AA, without a login of the
installer on the store.

- **Publishing** - a superadmin only, on the site's edit page (`modules/site/modedit.php`, part "App store"):
  published yes/no, a description and a version. Before saving, the site is exported as the store
  gives it - strict: every W is an E, and E `group-unpublished` for a constant group of a slice no
  published site carries (its values would go public). Any error refuses the save and is listed.
  Publishing records the site and the modules it uses (`app_modules`). "Uses" is edited by any
  admin of the site: while the site uses a module not recorded, the store does not serve it and the
  site form names the added modules. Only a superadmin's save with "Approve the changed modules"
  ticked records them - a save for another reason does not. A module taken away is dropped from the
  record.
  The strict export is the store's only - a superadmin still copies a published site as any other,
  and no copy is published.
- **Public account** - the user `aa-app-store` with the password in `AA\Central\AppStore` (public by
  design: it is in every AA's code). Create it on the store by Optimize "App store account"
  (superadmin); it must have no rights or groups. It cannot log in to the administration; on the
  responder it gets only an `apps` token (`Get_Jwt`) and runs only `Get_Apps` and `Get_App` with it
  (else 22), and never keeps a session. The account exists only with `PERM_LIB` `sql` (in LDAP the
  name may be anyone's): elsewhere the Optimize refuses and no user is the public account. `Get_App`
  then answers only the `definitions` of an install of one published site (else 16/18), checked on
  every request; an export error is answered as a bare `export-failed` - the findings stay on the
  store.
- **Limits** - per hour and client (an IPv4 address, or the /64 of an IPv6 one): 30 `Get_Jwt`, 120
  `Get_App`, 120 `Get_Apps`; per IPv6 /48: 120, 480, 480; from all clients together: 500 `Get_Jwt`,
  2000 `Get_App`, 2000 `Get_Apps` (else 23, "the app store is busy - try again later"). They count the
  `APPSTORE` log rows, so with `APPSTORE` in `$DO_NOT_LOG` (or when its row cannot be written) the
  store refuses every request of the account - better closed than without a limit.
  **Trade-off:** the global cap fails closed too - a flood from many addresses closes the store for
  everyone for up to an hour (installed apps and the rest of the AA are not affected). Put nginx
  `limit_req` on `central/responder.php` in front of AA, so that a flood is cut before it reaches
  the cap.
- **Installing** - `central/appstore.php` (superadmin with `PS_INSTALL_APP`, menu "App store...")
  logs in to the store by the public account (no password asked), lists its apps and hands one to the
  preview of "Install an app" - the same preview, S lint and write. A store run logs in again by
  itself when its token runs out. The store is
  `https://actionapps.org/apc-aa/` unless `AA_APP_STORE_URL` in `config.php` names another, or a row
  of the Central list; the address never comes from the request, and the hardened client rules apply.
- An unpublished site reaches another AA only by a login of a superadmin of the source (Install from
  another AA).

## State Check entries

On `admin/aa_statecheck.php`:

- **App transfers** - W for a transfer that has not moved on for an hour, E for one stopped by an
  error; links to its progress page.
- **Required slice settings** - W for slices whose required settings fields (`_` ids) have no value.
- **Central logins** - W for `central_conf` rows holding anything but an encrypted token (a password
  or a plain `jwt:` token of older Central lying in the database). Central never sends them; press
  Get Token for that AA (it overwrites the value). No repair action.
- **Server secret** - `AA_SECRET` missing (tokens are then keyed by a fallback derived from the
  DB password, `AA_ID` and DB name).
- **Get_Sessionid use** - who logged in by `Get_Sessionid` in the last 30 days.
- **Queued tasks of a missing class** - e.g. tasks of the removed Copy Slice left in the queue.

## For app authors

- **App boundary.** Build the app as a site whose "uses modules" lists its slices and Alerts modules.
  References outside it (a view of another slice, `{ids:<other slice>}`) keep the source id and are
  reported as W at export. Prefer named views (`{view.php?vid=<name>}`); numbers are rewritten only
  in known forms.
- **Settings.** A field whose id starts with `_` (`_` + 15 characters of `[A-Za-z0-9_.]`) is a slice
  setting, not an item field: one value per slice, edited on the slice's Setting page
  (`admin/slicefieldsedit.php`). The Fields page gives the id by field type (e.g.
  `_category.......`). Templates read it with `{modulefield:<slice id>:<field id>}`; the slice id is
  rewritten on install.
- **Defaults.** Put the value a fresh install should start with into the field's `input_default`
  with a generator from the list above (`txt:blue`, `now`, ...). Leave it empty for values the
  installer must choose (an API key, an e-mail) - those appear under "settings to fill in".
- **Required settings.** Tick "Required" on the settings field: State Check then warns while it is
  empty. The item form never asks for settings, so this is the only reminder.
- **What a transfer carries** beyond an install: the items (active bin or all bins) with
  `posted_by`/`edited_by` as text; readers of Reader Management slices with password hashes (byte for
  byte) and 2FA secrets; the module owner (name, e-mail); the slice reading password; the site's
  domains (S, dropped when taken or when the source is the same AA); the setting values. Never:
  uploaded files, user profiles, `rssfeeds`, `email_notify`, `short_id` (renumbered), module ids,
  view and e-mail numbers.
