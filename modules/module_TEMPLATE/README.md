# Creating a new module type

A **module type** (Slice `S`, Site `W`, Alerts, Links, Polls `P`, Jump `J`, …) is a kind of thing an
AA installation can hold many instances of. Every instance has a row in the `module` table, a
32-digit hexadecimal `module_id` (for slices it is the `slice_id`), its own permissions and its own
admin pages. This guide is the checklist for adding a new type; it was last verified against the
code on 2026-09-19.

`modules/alerts/` is the reference for the patterns below (its `modedit.php` is the most recently
modernised one). This directory holds only a bare `index.php` to copy; the other pages are given
here as text on purpose — `module_TEMPLATE/` ships to running installations, so it must not contain
pages that could write anything.

## What you touch

| Where | What | Why |
|---|---|---|
| `include/slice.class.php` | class `AA_Module_Xxx extends AA_Module` | registers the type |
| `include/perm_core.php` | block of `PS_XXX_*` letters + a row in `AA_Perm::PERMS_ROLES_MODULES` | permissions |
| `include/menu_util.php` | the type in `$type_rank` and `$other_type_labels` (and `$by_module_type` once it has an icon) | module switcher |
| `modules/xxx/` | `index.php`, `modedit.php`, `menu.php`, `moddelete.php` | admin pages |
| `misc/mgettext/cli.php` | a language section for `modules/xxx/` | translations |
| `include/metabase_schema.php` + an `AA\Optimize\*` class | only if the module needs tables | schema |
| `tests/Unit/Legacy/` | a test for the registration and the permission letters | |

Nothing else is needed: there is no `$MODULES` array in `include/constants.php` any more.

## 1. The module class

`AA_Moduleregistry` finds every declared class named `AA_Module_*`, so the class must sit in a file
that is always loaded — put it in `include/slice.class.php` next to the other module classes.

```php
/** Xxx module - one instance is … */
class AA_Module_Xxx extends AA_Module
{
    /** @inheritDoc */
    public static function getModuleconfig(): AA_Moduleconfig
    {
        //                          type   name shown in "Add module"   class            directory       menu file               lang files  templates
        return new AA_Moduleconfig('Xxx', _m('Xxx - what it is for'), 'AA_Module_Xxx', 'modules/xxx/', 'modules/xxx/menu.php', [],          false);
    }
}
```

- **type** is stored in `module.type` (`char(16)`). Mind its **first letter**: `include/um_util.php`
  keys the role lists of the user-management pages by it, so two types starting with the same
  letter share one role list. Either pick a free first letter (taken: `S`, `W`, `A`, `L`, `P`, `J`)
  or give the type exactly the same roles as the type it collides with.
- **lang files**: leave `[]` when the module's `modedit.php` offers `Langs::getFiles('<section>')`
  (Alerts does); Site and Polls still list their files here and read them with
  `AA_Moduleregistry::info('W', 'langs')`.
- **templates** `true` makes "Add module" show a selectbox of existing instances to copy from
  (Links, and Site/Polls in their own way); the chosen id arrives in `modedit.php` as `template[Xxx]`.
- A module without admin pages of its own (Jump) passes `'include/menu.php'` as its menu.
- Override `_deleteModules(array $module_ids): bool` **only** when the module owns tables or objects
  to clean up (see `AA_Module_Polls`); the inherited one already returns `true`.

Once the class exists, the type shows up in *AA → Add slice / module* by itself and its **Add**
button leads to `modules/xxx/modedit.php?no_slice_id=1` (`admin/slicedit.php`).

## 2. Permissions

A permission is a letter; what a user may do with an instance is a string of letters stored for
(user or group) × instance. Each module type gives the letters its own meaning. Add a block to
`include/perm_core.php` and keep the letters inside the ranges of the generic roles, so
`AUTHOR` / `EDITOR` / `ADMINISTRATOR` keep working:

```php
//---------- Xxx -----------------
// author        - possibly letters 'abcdefg'
const PS_XXX_VIEW     = "a";   // slice | see the module
// editor        - possibly letters 'hijklmnopqrs'
const PS_XXX_EDIT     = "h";   //       | change its content
// administrator - possibly letters 'ABCDEFGHIJKLMNOP'
const PS_XXX_SETTINGS = "A";   //       | module settings
```

and one row in `AA_Perm::PERMS_ROLES_MODULES`: `'Xxx' => ["EDITOR","ADMINISTRATOR"],`.

- `A` means "settings" in every module type and **`E` must stay "manage users"** — the generic
  user-management pages check `PS_USERS` (`E`) whatever the type is.
- Roles are only presets: any string of letters can be granted to a user directly.
- A page checks the current instance with `IfSlPerm(PS_XXX_VIEW)`; code that knows the id uses
  `CheckPerms($uid, 'slice', $module_id, PS_XXX_VIEW)`. The object type is `'slice'` for every
  module type (historical name).

## 3. Module switcher

In `GetModuleSelection()` (`include/menu_util.php`) add the type to `$type_rank` (order) and to
`$other_type_labels` (section heading). Add it to `$by_module_type` only together with a CSS rule
`select[name="sel_module"] option.aa-ic-<code>::after` in `admin-ecn.css`; without the entry the
rows get the default icon, with an entry but no rule they get none. The switcher is rendered only
for users with more than one module and is loaded lazily (`cached.php?keystr=…`), so look there
when checking it.

## 4. Pages in `modules/xxx/`

Every page starts with `require_once __DIR__ . "/../../include/init_page.php";` — it opens the
session, shows the login form when needed, loads `perm_core.php` and sets `AA::$module_id` from the
request. When the request names no module (or one the user cannot access), it falls back to a module
of the page's own type; but an id of **another type** that the user *can* access passes through, and
the letter `A` means "settings" there too. So every page must check the type itself:

```php
if (!AA_Module_Xxx::getOwnModule(AA::$module_id)) {   // a class check: null for an id of another type
    MsgPage(_m("Xxx module not found"));              // MsgPage() and go_url() never return - no exit needed
}
if (!IfSlPerm(PS_XXX_VIEW)) {
    MsgPage(_m("You do not have permission to see this module"));
}
```

**`index.php`** — the main page. Copy the one in this directory. `new AA_Adminpageutil($menu, $submenu)`
takes the key of the main menu and the key of the submenu item from `menu.php`. Call
`$apage->setForm();` before `printHead()` when the page should *not* be wrapped in the default
`<form name="f" method="post">` (any page that brings its own forms or is an application shell).

**`modedit.php`** — add a new instance and edit its settings. Follow `modules/alerts/modedit.php`;
the shared helpers in `include/modutils.php` do the work: `ValidateModuleFields()`,
`CreateNewOwner()`, `WriteModuleFields()` (writes the `module` row and, on insert, calls
`AddPermObject($module_id, "slice")`) and `RenderModuleFieldsForm()` (name, URL, priority, owner,
deleted). Rules learned the hard way:

- Read input only through `AA\Util\InputVars`. Set `$no_slice_id = true` from the request **before**
  including `init_page.php` when adding (there is no module yet).
- Decide *add or update* **once** and use it for the permission check, for the processing gate and
  for the write target — otherwise a request carrying both `insert` and `update` passes the "add"
  check and then updates an existing module:

  ```php
  $is_add = $no_slice_id || $insert;
  if ($is_add) {
      if (!CheckPerms(AuthGetUid(), "aa", AA_ID, PS_ADD)) { MsgPage(_m("You do not have permissions to add slice")); }
  } elseif (!AA_Module_Xxx::getOwnModule($module_id)) {
      MsgPage(_m("Xxx module not found"));
  } elseif (!IfSlPerm(PS_XXX_SETTINGS)) {
      MsgPage(_m("You do not have permission to change the settings of this module"));
  }
  if ($is_add ? $insert : $update) {
      // validate …
      $module_id = WriteModuleFields($is_add ? null : $module_id, $superadmin, 'Xxx', $name, $slice_url, $priority, $lang_file, $owner, $deleted);
      AA::Pagecache()->invalidateFor($module_id);   // cached pages may show the module name or URL
  }
  ```
- `ValidateModuleFields()` takes the URL as optional and `lang_file` as any text. If the module
  needs the URL, check it yourself; accept only a `lang_file` offered by the select
  (`array_key_exists($lang_file, Langs::getFiles('xxx'))`) — a wrong name is later echoed by
  `bind_mgettext_domain()`.
- Do **not** pass `Msg` from the request to `printHead()`: it is printed unescaped. Call
  `$apage->printHead($err);`.
- `module.slice_url` is a free column every type may use for "the address of this thing". A module
  with several settings gets an `AA_Modulesettings_Xxx` class (see `AA_Modulesettings_Site`), a
  module with data gets its own tables (step 6).

**`menu.php`** — `function get_aamenus(): array`, same shape as `modules/alerts/menu.php`: one
`"level" => "main"` entry with a `"submenu"`, the submenu `"items"` with `cond` / `href` / `label`,
and at the end `return array_merge($aamenus, GetCommonMenu($profile));`. Add an `exact_href` "view"
entry only if the module has a public page (`$r_slice_view_url` is `module.slice_url`).

**`moddelete.php`** — mandatory one-liner. `admin/slicedel.php` links the per-row *Delete* of every
type other than a slice to `<module dir>/moddelete.php`; without the file that link is a 404:

```php
<?php
require_once __DIR__ . "/../../admin/slicedel2.php";
```

## 5. Texts and translations

Write every visible string in English as `_m("…")`. Language files (`include/lang/<lang>_<section>_lang.php`)
are generated by `misc/mgettext/cli.php`; add the module directory to `$LANG_GROUPS` there as a new
section (`'xxx' => ['modules/xxx/']`), so the strings do not inflate the `news` section loaded on
every admin page. Then offer `Langs::getFiles('xxx')` in `modedit.php`. Until the section exists,
`Langs::getFiles('news')` works and the module's strings simply stay English. Translations are
filled in with the `/update-langs` workflow.

## 6. Database tables (only when needed)

Prefer what AA already has: a slice (items get fields without a schema change, history, permissions,
views) for anything of modest volume; `AA_Object` (`object_*` tables) for settings-like objects. A
new table is for high-volume machine data only. Define it in `include/metabase_schema.php`, prefix
its name with the module name (`xxx_something`), create it with an own `AA\Optimize\*` class of type
`sql_update` (idempotent `test()` / `repair()`, run by `service/sql_update.php` and *AA → Optimize*).
In `include/metabase.class.php` name the column that holds the module id in
`AA_Metabase::getModuleFields()` (`$MODULE_KEYS`) and the table's binary-id columns in
`AA_Metabase::getPacked()` (`$PACKED`), and delete the instance's rows in `_deleteModules()`.
`doc/aadb.sql` and `sql_update.php3` are history.

## 7. Tests

Legacy classes are testable with the autoloader-only bootstrap once the `config.php` constants
exist. Put the test in `tests/Unit/Legacy/` with the usual block (`AA_INSTAL_URL`, `AA_INSTAL_PATH`,
`IMG_UPLOAD_PATH`, `AA_BASE_PATH` and — when `perm_core.php` gets loaded — `PERM_LIB => 'sql'`), and
load the `PS_*` constants with `class_exists(AA_Perm::class);` in `setUpBeforeClass()` (constants are
not autoloaded). Worth pinning: `AA_Moduleregistry::info('Xxx', 'dir' | 'menu' | 'class')`, that the
letters are unique and inside their role ranges, and that types sharing a first letter have identical
role lists. `tests/Unit/Legacy/ModuleFallbackTest.php` has a table of scripts and their module type —
add the new pages there.

## 8. Before you call it done

`composer check` green, then in a browser (or with curl and a cookie jar): the type is offered in
*Add slice / module*; an instance can be added and edited; wrong input keeps the form with an error;
the main page opens for a user with the permission and shows the login form without one; a module
id of another type is refused by every page; the instance appears in the module switcher; the
per-row *Delete* link does not 404. Never crawl the admin while checking — many GET links in it
change data.
