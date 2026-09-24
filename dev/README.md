# dev/ — build inputs

Everything here exists only to **regenerate** files that ship in the installation.
Nothing in this directory is read at runtime.

**Installing AA on a server? Delete `dev/`, `doc/` and `tests/`** — the application
does not need any of them.

| Directory | Rebuilds | How |
|---|---|---|
| `aajslib/` | `javascript/aajslib-{jquery,legacy}.js` | `dev/aajslib/merge_common_js.sh <name>-src.js`, then `uglifyjs` |
| `codemirror6/` | `javascript/codemirror6.min.js` | `dev/codemirror6/build.sh` |
| `central/` | `src/Central/Package/aa-app-1.xsd` (schema of the app package) | `php dev/central/aa-app-xsd.php` - after a change of `include/metabase_schema.php` or `AA\Central\Package\Schema`; `SchemaTest` fails until it is rerun. A new column in `module`, `slice`, `site` or `alerts_collection` (installation state) makes it throw until you classify it in `Schema::CARRIED` or the left-out list in `Schema::ROWS` - never carry paths, passwords, permissions or ids of the source AA |

`aajslib/` also holds `aajslib-common.js`, the shared vanilla source every variant
includes. After editing it, rebuild **and** re-minify - the `.min.js` is what loads - then
bump `AA_JS_VERSION` in `include/constants.php`.

Build steps need `node`/`npm` and write their output into the tree; run them
only when upgrading the library in question, and commit the regenerated file
together with the input you changed.

`npm install` deliberately happens in a temporary directory, never inside the
working copy: this tree is rsynced to the live server with `--delete` and has no
exclude for `node_modules`.
