# Projections

A **surface** is a named group of files declared by a package author. A
**projection** is a consumer's selection of surfaces from one normally installed
package. Composer downloads the complete archive; the plugin then deletes every
file that is not in the selected surfaces.

Use a projection when one package ships several optional modules and an
application needs only some of them. It is not a metapackage and not a new
package: it is a pruned installation of the same package at the same version.

```text
acme/monolith 1.4.0
├── core          selected
├── javascript    selected
├── lua           removed
└── every other   removed
```

Packagist does not serve partial archives, so projection does **not** reduce
network transfer. It reduces the installed tree and the files exposed to the
application.

## Author configuration

Surfaces are declared in the package's own root `composer.json`, under
`extra.source-plugin.surfaces`:

```json
{
    "name": "acme/monolith",
    "extra": {
        "source-plugin": {
            "surfaces": {
                "core": {
                    "include": ["composer.json", "LICENSE", "src/Core/**"]
                },
                "javascript": {
                    "include": ["src/JavaScript/**", "resources/javascript/**"]
                },
                "lua": {
                    "include": ["src/Lua/**", "resources/lua/**"]
                }
            }
        }
    }
}
```

A surface must declare at least one include path. Surface names may contain
letters, digits, dots, dashes, and underscores, and must start with a letter or
digit.

## Include patterns

Paths are package-relative. Supported tokens:

| Token | Matches |
| --- | --- |
| `*` | Any run of characters within one path segment. |
| `**` | Any run of characters across segments. |
| `?` | One non-separator character. |
| `[a-z]` | A character class; `[!a-z]` / `[^a-z]` negate. |

A pattern that names a directory retains that directory's whole subtree, so
`src/Core` and `src/Core/**` select the same files. `src/Core/*.php` only
matches PHP files directly in `src/Core`; use the directory pattern when
nested files must survive.

Rejected paths:

- absolute paths (`/...`, `C:...`);
- `..` traversal;
- backslashes;
- colons;
- empty paths;
- unterminated or malformed character classes.

The complete allowlist is resolved before anything on disk is changed.

## Consumer configuration

```json
{
    "require": {
        "webong/composer-source-plugin": "^1.0",
        "acme/monolith": "^1.0"
    },
    "extra": {
        "source-plugin": {
            "projections": {
                "acme/monolith": {
                    "surfaces": ["core", "javascript"]
                }
            }
        }
    }
}
```

The resulting tree:

```text
vendor/acme/monolith/composer.json
vendor/acme/monolith/LICENSE
vendor/acme/monolith/src/Core/**
vendor/acme/monolith/src/JavaScript/**
vendor/acme/monolith/resources/javascript/**
```

The Lua files and every other unselected path are removed. Selected surface
names are sorted, so declaration order does not matter. An unknown surface is
an error, not a no-op.

## Mandatory files

The plugin always retains:

- the installed package's root `composer.json`;
- its own marker at `.source-plugin/projection.json`;
- a root `.git` directory, when Composer installed from source.

`LICENSE`, `NOTICE`, and `README` are **not** implicit. Put them in a base or
core surface if they must survive:

```json
{
    "core": {
        "include": ["composer.json", "LICENSE", "README.md", "src/Core/**"]
    }
}
```

`composer.json` is mandatory because Composer and this plugin both read package
metadata after projection. It is also why a projection cannot hide a package's
declared dependencies.

## Lifecycle

```text
composer install/update
    └── post-package-install / post-package-update
            project the package immediately
    └── pre-autoload-dump
            reconcile packages that were not reinstalled
    └── post-autoload-dump
            generate aliases from the retained files
    └── post-install-cmd / post-update-cmd
            finalize projections when no autoload dump happened
```

Projection runs after the archive is extracted and before the autoloader is
dumped. No nested Composer command is executed.

| Command | Projection behaviour |
| --- | --- |
| `composer update` | Projects and may advance `composer-source.lock`. |
| `composer install` | Projects only when the committed state matches; does not write state. |
| `composer reinstall <pkg>` | Restores the archive, projects, and may advance state. |
| `composer dump-autoload` | Verifies committed state and repairs a projected tree for that selection; cannot restore deleted files or apply an uncommitted selection change. |
| `composer update --no-install` | Resolves only; no package is installed and no state is written. |
| `composer update --dry-run` | Composer dispatches no package or script events; nothing changes. |
| `--no-plugins` | Normal install; the full package remains and no projection happens. |
| first run that installs the plugin | The plugin is not active yet; the full package is installed. |

The bootstrap is therefore:

```sh
composer update   # installs the plugin and the full packages
composer update   # applies and records projections
```

A lock-based clone follows the same rule:

```sh
composer install  # installs the plugin and full packages
composer install  # applies the committed projection state
```

`prefer-dist` is recommended. Source installs can be projected, but the
resulting git checkout has deleted tracked files. Composer may then refuse a
later source update until `composer reinstall`; the plugin prints a warning.

## State in `composer-source.lock`

Projection state lives in `composer-source.lock` beside `composer.json`. It
shares the file with mirror baselines, and each feature preserves the other's
entries. Commit it.

Each projection entry records:

| Field | Meaning |
| --- | --- |
| key | Installed package name. |
| `format` | Projection state format version (`1`). |
| `reference` | Installed source or dist reference; a dist checksum when neither exists. |
| `version` | Pretty installed version. |
| `surfaces` | Selected surface names, sorted. |
| `patterns` | Complete normalized include list, including `composer.json`. |
| `patterns_digest` | SHA-256 of the newline-joined pattern list. |
| `definition_digest` | SHA-256 of **all** declared surfaces and their include lists. |

No absolute paths are stored. On `composer install` and `composer dump-autoload`
these values must match the committed entry exactly. A missing entry or a
different value is an actionable error instead of silent drift.

Because `definition_digest` covers every declared surface, adding or editing an
unselected surface in a new package version also invalidates the committed
state. That is intentional: the allowlist is only trustworthy if the
definitions behind it are the ones that were reviewed.

## Narrowing, widening, and reinstalling

Narrowing is safe and needs no download:

```text
["core", "javascript"]  ->  ["core"]
```

Widening cannot invent files that a previous projection deleted. If a newly
selected surface needs a file recorded as removed, the plugin fails before
changing anything:

```text
Cannot widen projection of [acme/monolith]: 12 previously removed file(s) are
needed by the newly selected surface(s) [lua], including [src/Lua/Runtime.php].
Reinstall the package to restore the full archive: composer reinstall acme/monolith
```

A fresh install clears the previous removal history, because Composer has just
extracted the complete archive. A compatible `composer update` or
`composer reinstall` can therefore widen successfully. A failed widening leaves
the previous state and tree untouched.

## Path and filesystem safety

Before anything is deleted, the plugin:

1. parses every surface declaration;
2. validates every selected name;
3. resolves the complete keep/remove plan;
4. enumerates package files without following symlinks out of the root.

Safety rules:

- paths must stay package-relative; traversal, absolute paths, backslashes,
  colons, and malformed globs are rejected;
- a symlink that resolves outside the package root aborts the projection;
- symlinked directories are never traversed (the link itself may be kept or
  removed);
- a symlinked package installation, such as a `path` repository with
  `"symlink": true`, is rejected:

  ```text
  Cannot project [...]: the installed package path is a symlink. Use a copied
  installation rather than a symlinked path repository.
  ```

- the projected tree is built in a sibling staging directory, retained files
  are copied with their permissions, and the completed tree is swapped into
  place atomically;
- a failure before the swap leaves the original package intact; the old tree is
  removed only after a successful swap;
- Composer's download cache and upstream checkouts are never modified.

## Autoload and dependency limitations

Package metadata is resolved before projection. The root autoload contract has
to remain valid after optional files disappear:

- PSR-4/PSR-0 mappings may point at selected or absent directories;
- unconditional `autoload.files` entries must belong to retained/core files;
- optional bootstrap files should be routed through one retained dispatcher
  that conditionally discovers the selected modules.

The plugin drops in-memory `files`, `classmap`, and `exclude-from-classmap`
entries whose target no longer exists, so Composer does not try to require a
deleted file. It does not add module autoloading or solve dependencies.

A projection never merges a nested `composer.json` and never invents
dependencies after the solver has run. Optional third-party packages must be
normal requirements, suggestions, or a future explicitly designed mechanism.
The containing package must declare its Composer dependencies and autoload
contract normally.

## Conflicts and exclusions

A projection references a concrete installed package and must select at least
one declared surface. The plugin package itself cannot be projected because it
must remain fully installed to run.

A package cannot simultaneously be projected and:

- **mirrored** — a mirror syncs the full package into a working copy;
- **a loader** — a loader chooses a candidate before resolution;
- **a workspace** — a workspace is an implicit inline loader.

Both combinations are rejected at activation. Namespace aliases are allowed and
are generated after projection, so only retained classes are aliased or
rebased.

## Example: optional gateway modules

```json
{
    "name": "acme/gateway",
    "autoload": {
        "psr-4": { "Acme\\Gateway\\": "src/" }
    },
    "extra": {
        "source-plugin": {
            "surfaces": {
                "core": { "include": ["composer.json", "LICENSE", "src/Core/**"] },
                "reverb": { "include": ["src/Reverb/**", "config/reverb.php"] },
                "mercure": { "include": ["src/Mercure/**", "config/mercure.php"] },
                "shell": { "include": ["src/Shell/**", "bin/**"] }
            }
        }
    }
}
```

```json
{
    "extra": {
        "source-plugin": {
            "projections": {
                "acme/gateway": {
                    "surfaces": ["core", "reverb", "mercure"]
                }
            }
        }
    }
}
```

The package keeps one root `autoload` contract and routes optional bootstraps
through retained code. The plugin has no gateway-specific behavior.

## Limits

- **Full download, pruned install.** Network transfer is unchanged.
- **One package at a time.** Each projection references one installed package.
- **No dependency invention.** The solver has already run.
- **No restoration from the projected tree.** Widening needs a reinstall or a
  fresh update.
- **Not a package split.** The projected package keeps its original name and
  version.
