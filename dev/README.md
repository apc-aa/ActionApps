# dev/ — build inputs

Everything here exists only to **regenerate** files that ship in the installation.
Nothing in this directory is read at runtime.

**Installing AA on a server? Delete `dev/`, `doc/` and `tests/`** — the application
does not need any of them.

| Directory | Rebuilds | How |
|---|---|---|
| `codemirror6/` | `javascript/codemirror6.min.js` | `dev/codemirror6/build.sh` |

Build steps need `node`/`npm` and write their output into the tree; run them
only when upgrading the library in question, and commit the regenerated file
together with the input you changed.

`npm install` deliberately happens in a temporary directory, never inside the
working copy: this tree is rsynced to the live server with `--delete` and has no
exclude for `node_modules`.
