# Composer Source Plugin

`webong/composer-source-plugin` selects the winning source when a Composer
package is available both as an inline merged manifest and as an outline
package. It also retains the optional namespace-compatibility alias generator.

## Source selection

Configure the winner in the consuming application's root `composer.json`:

```json
{
    "extra": {
        "source-plugin": {
            "loaders": {
                "webong/web-proxy": {
                    "type": "auto",
                    "path": "ext/web-proxy",
                    "manifest": "ext/web-proxy/composer.json"
                }
            }
        }
    }
}
```

The loader type can be:

- `inline`: the local manifest/path source wins.
- `outline`: the normal Composer package wins.
- `auto`: inline wins when the configured manifest exists; otherwise outline wins.

The plugin removes the losing package candidate before Composer resolves the
dependency pool. When the outline source wins, it also removes the inline
manifest's merged autoload and dependency contributions. This prevents two
implementations from exposing the same namespace.

## Local manifest merging

The plugin is compatible with
[`wikimedia/composer-merge-plugin`](https://github.com/wikimedia/composer-merge-plugin),
which is suggested rather than required:

```json
{
    "require": {
        "webong/composer-source-plugin": "^1.0",
        "wikimedia/composer-merge-plugin": "^2.1",
        "webong/web-proxy": "@dev"
    },
    "extra": {
        "merge-plugin": {
            "include": ["ext/web-proxy/composer.json"]
        },
        "source-plugin": {
            "loaders": {
                "webong/web-proxy": {
                    "type": "inline",
                    "manifest": "ext/web-proxy/composer.json"
                }
            }
        }
    }
}
```

The inline and outline definitions may both be declared, but only the
configured winner remains active in the Composer build.

For local development without manifest merging, a path repository is also
supported:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "ext/web-proxy",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "webong/web-proxy": "@dev"
    }
}
```

## Local source mirrors

A mirror keeps a working copy of an installed dependency at a path in your
repository, and syncs upstream into it whenever you run `composer update`.

Configure it under `extra.source-plugin.mirrors`:

```json
{
    "require": {
        "acme/fluent": "^2.0"
    },
    "extra": {
        "source-plugin": {
            "mirrors": {
                "acme/fluent": {
                    "path": "ext/fluent"
                }
            },
            "aliases": {
                "acme/fluent": {
                    "Acme\\Fluent\\": "Local\\Fluent\\",
                    "type": "rebase"
                }
            }
        }
    }
}
```

With that, `composer update` downloads the new release into `vendor/` as
usual, and the plugin then syncs it into `ext/fluent`. The package stays
installed remotely, so upstream updates flow in normally, and your working
copy stays on disk under version control.

Bring the working copy into the build with
[`wikimedia/composer-merge-plugin`](https://github.com/wikimedia/composer-merge-plugin),
which is suggested rather than required:

```json
{
    "extra": {
        "merge-plugin": {
            "include": ["ext/fluent/composer.json"]
        }
    }
}
```

**No `path` repository is needed.** A `path` repository with
`"symlink": true` would make Composer treat your working copy as the package,
which stops upstream updates from ever being downloaded. A mirror is the
opposite: the remote package stays the installed one, and the mirror is the
copy you edit. When a `rebase` alias is configured, the rebase reads from the
mirror path.

### Mirrors and loaders are mutually exclusive

A package cannot be in both `mirrors` and `loaders`, and the plugin rejects
the combination at activation:

> Package [acme/fluent] cannot be both mirrored and loadable: a mirror needs
> the remote package installed, while a loader removes it from the pool.
> Configure one or the other.

`mirrors` requires the remote package to stay installed so it can be synced
and updated. `loaders` deliberately removes a package from the pool. Pick one
per package.

### How local edits are protected

To keep sync metadata outside working copies, opt into a project lock:

```json
{
    "extra": {
        "source-plugin": {
            "mirror-state": "lock",
            "mirrors": {
                "webong/cogent": { "path": "ext/cogent" }
            }
        }
    }
}
```

Run `composer update` with autoload generation enabled. The plugin writes
`composer-source.lock` beside `composer.json`, containing each package's
upstream reference, baseline hashes, and namespace mapping. Commit this file
alongside `composer.lock` and the mirrored sources. Existing
`.source-plugin/sync.json` files are read during migration and removed only
after the project lock has been saved successfully. Other files inside
`.source-plugin/` are retained.

In this mode, `install` and `dump-autoload` use the recorded baseline without
changing the project lock. If the installed reference does not match it, sync
is skipped with a warning to run `composer update`. Resolution-only updates
(`--no-install`), dry runs and updates with plugins disabled cannot erase this
file. A namespace mapping change against an existing project baseline is
rejected; it requires an explicit working-copy migration.

Composer rebuilds `composer.lock` from dependency data and does not preserve
arbitrary custom fields. The companion file avoids losing merge history on
those rewrites. A custom `COMPOSER=other.json` uses `other-source.lock` beside
that manifest. The default `mirror-state: "local"` retains existing behavior;
after migrating, keep `lock` enabled (switching back does not export the state).

Merging is file-level, not line-level. In the default local mode, the plugin records what the mirror
looked like when it was last synced in `ext/fluent/.source-plugin/sync.json`,
committed alongside your working copy so the baseline is reproducible across
machines. Commit it.

On each sync, for every file upstream ships:

| Situation | Result |
| --- | --- |
| File already matches upstream | Untouched |
| You have not changed it, upstream has | Upstream version written |
| You changed it, upstream has not | **Your edit kept** |
| Both changed | **Reported as a conflict**, never overwritten |

A conflict leaves your file alone and writes the upstream version next to it as
`path/to/File.php.upstream`, so you can diff and decide. The conflict persists
across syncs until the file actually matches upstream, which means you can also
resolve it by editing your copy to match.

Files upstream deletes are removed only when you have not modified them;
otherwise they are kept with a warning. Files that only exist locally are
never touched and never recorded as baseline.

Two limits are worth stating plainly:

- **A file changed on both sides is reported, never auto-merged.** There is no
  line-level merge. A conflicted file is left exactly as you wrote it.
- **A working copy with no `sync.json` is treated as unbased.** Existing local
  files are kept, with a warning. Current upstream content becomes the
  baseline so repeated syncs preserve those local edits and later upstream
  changes produce conflicts.

`composer.json` in the mirror is synced like any other file, so it is subject
to the same protection: edit it freely, and an upstream change to it shows up
as a conflict rather than clobbering your edits.

### Rebase copy policy

Existing aliases keep the whole-package copy policy and package-relative layout.
To produce a smaller artifact, opt into `copy: "autoload"` on a package-scoped
rebase alias:

```json
{
    "acme/library": {
        "Acme\\Library\\": "Local\\Library\\",
        "type": "rebase",
        "copy": "autoload",
        "include": ["resources"],
        "exclude": ["src/Tests", "src/PhpStan"]
    }
}
```

This copies the mapped PSR-4 source directories, declared `autoload.files`,
and explicit `include` paths. `exclude` paths remove complete subtrees at any
depth. Paths are package-relative, without globs. Runtime resources outside
the source roots must be explicitly included. Filenames and directory names
alone cannot determine whether a file is used at runtime. The generated layout
remains `vendor/composer/rebased/<vendor>--<package>/<original-path>`.

### Rebase the mirror itself

By default a rebase leaves the mirror upstream-shaped and writes its rebased
copy under `vendor/composer/rebased/`. To make the working copy itself use the
target namespace, opt into the explicit `mirror` destination:

```json
{
    "webong/cogent": {
        "Webong\\Cogent\\": "Zorvia\\Cogent\\",
        "type": "rebase",
        "destination": "mirror"
    }
}
```

The source mirror still synchronizes from the installed `webong/cogent`
package. During sync the plugin transforms PHP source into the target namespace
before writing it to the mirror and records the transformed content as its
baseline. Local edits remain protected and upstream conflicts retain the same
file-level behavior. Composer's package name is unchanged.

### Package surfaces and projections

A **surface** is a named group of files declared by a package author. A
**projection** is a consumer's selection of surfaces from one normally
installed package.

Composer downloads the package's **complete dist/source archive** as usual.
The plugin then removes every unselected file from the installed copy, so
`vendor/acme/monolith` retains only the chosen surfaces. Packagist does not
serve partial archives, and this feature does not reduce network transfer.

A projection is not a metapackage and not an independently versioned package.
It is a projected installation of one package.

#### Author configuration

Declare surfaces in the package's own root `composer.json`:

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

Include paths are package-relative. `*` matches within one path segment,
`**` matches across segments, `?` matches one non-separator character, and
`[a-z]` character classes are supported. Naming a directory includes its whole
subtree, so `src/Core` and `src/Core/**` select the same files.

#### Consumer configuration

Select surfaces in the consuming root `composer.json`:

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

The result is:

```text
vendor/acme/monolith/composer.json
vendor/acme/monolith/LICENSE
vendor/acme/monolith/src/Core/**
vendor/acme/monolith/src/JavaScript/**
vendor/acme/monolith/resources/javascript/**
```

The Lua files and every other unselected path are removed.

#### Mandatory files

The plugin always retains the installed package's root `composer.json` and its
own marker at `.source-plugin/projection.json`. It also preserves a root
`.git` directory when Composer installed the package from source, because it is
VCS metadata rather than author content.

`LICENSE`, `NOTICE`, and `README` are **not** retained implicitly. Include them
in a base/core surface when they must survive, as the example does for
`LICENSE`.

#### Lifecycle and projections

Projection runs on Composer's `post-package-install` and
`post-package-update` events, after the archive is extracted and before the
autoloader is dumped. A `pre-autoload-dump` pass reconciles packages that were
not reinstalled, which is what makes narrowing work. No nested Composer
command is ever executed.

The first Composer run that installs this plugin cannot use it: Composer has not
activated a plugin it just installed. On a clean project the bootstrap is:

```sh
composer update   # installs the plugin and the full packages
composer update   # applies and records projections
```

The same two-step rule applies to a lock-based clone: the first
`composer install` installs the plugin and the full packages, and a second
`composer install` applies the committed projection state.

Projection is plugin-dependent:

- `composer install` and `composer update` project after installation.
- `composer dump-autoload` validates the committed state and repairs a
  projected tree for that committed selection, but it cannot restore deleted
  files or apply an uncommitted selection change.
- `--dry-run` changes nothing because Composer does not dispatch package or
  script events for it.
- `--no-install` resolves only; no package is installed and no projection state
  is written.
- `--no-plugins` performs a normal install and leaves the full package in
  `vendor/`; projection is simply absent.
- `prefer-dist` is recommended. Source installs are projected too, but the
  resulting git checkout has deleted tracked files. Composer may then refuse a
  later source update until `composer reinstall`; the plugin emits a warning.

#### Projection state in composer-source.lock

Projection state lives in `composer-source.lock` beside `composer.json`. It is
shared with the mirror baseline, so neither feature can erase the other's
entries. Commit it. Each projection entry records:

- the installed package name (as the map key);
- the installed reference and version;
- the selected surface names;
- the normalized include patterns and their SHA-256 digest;
- the complete surface-definition digest;
- a projection format version.

No absolute paths are stored. On `composer install` (and `dump-autoload`) the
selected surfaces, package reference, include rules, and surface definitions
must all match the committed entry. A mismatch is an actionable error instead
of silent drift. On `composer update` valid changes advance the state with an
atomic rewrite, preserving mirrors and other projected packages.

#### Narrowing, widening, and reinstalling

Narrowing is safe and needs no download:

```json
["core", "javascript"] -> ["core"]
```

Widening cannot invent files that a previous projection deleted. If the newly
selected surface is missing, the plugin fails and tells you to restore the
archive:

```sh
composer reinstall acme/monolith
```

A compatible `composer update` or `composer reinstall` advances the committed
surface list. A failed widening leaves the previous state untouched.

#### Path and filesystem safety

Before anything is deleted the plugin parses all surfaces, validates every
selected name, resolves the complete keep/remove plan, and enumerates package
files. Paths must be package-relative: absolute paths, empty paths, `..`
traversal, backslashes, colons, and malformed globs are rejected. Any symlink
that resolves outside the package root aborts the projection, and symlinked
directories are never traversed. A symlinked package installation (for example
a `path` repository with `"symlink": true`) is rejected.

The projected tree is built in a sibling staging directory, retained files are
copied with their permissions, and the completed tree is swapped into place
atomically. A failure before the swap leaves the original package intact; the
old tree is removed only after a successful swap. Composer's download cache and
upstream checkouts are never modified.

#### Autoload and dependency limitations

Package metadata is resolved before projection. Keep the root autoload contract
valid after optional files disappear:

- PSR-4/PSR-0 mappings may point at selected or absent directories.
- Unconditional `autoload.files` entries must belong to retained/core files.
- Optional bootstrap files should be routed through one retained dispatcher
  that conditionally discovers the selected modules.

The plugin drops in-memory `files`, `classmap`, and `exclude-from-classmap`
entries whose target no longer exists, so Composer does not try to require a
deleted file. It does not add module autoloading or dependency solving. The
containing package must declare its Composer dependencies and autoload
contract normally; optional third-party packages must be normal requirements,
suggestions, or a future explicitly designed mechanism. A projection never
merges a nested `composer.json` and never invents dependencies after the solver
has run.

#### Conflicts and exclusions

A projection references a concrete installed package and must select at least
one declared surface. A package without author-declared surfaces cannot be
projected by name. The plugin package itself cannot be projected.

A package cannot simultaneously be projected and:

- **mirrored** — a mirror synchronizes the full package into a working copy;
- **a loader** — a loader chooses a package candidate before resolution.

Both combinations are rejected at plugin activation. Namespace aliases are
allowed and are generated after projection, so only retained classes are
aliased or rebased.

#### Gateway-shaped example

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
through retained code. The plugin has no Gateway-specific behavior.

#### Feature comparison

| Feature | What it does |
| --- | --- |
| loader | Selects an inline or outline source candidate before resolution. |
| mirror | Copies and synchronizes an installed dependency into a working tree. |
| projection | Keeps selected named surfaces from one installed package. |
| alias / rebase | Changes namespace exposure. |
| `copy: "autoload"` | Limits copied mirror/rebase content using autoload metadata. |

### Supported runtimes and verification

The plugin supports PHP 8.1 and later and Composer plugin API 2.3 and later.
CI runs unit tests and real Composer fixtures on PHP 8.1 through 8.5, with
Composer 2.3 and current Composer 2. Test dependencies resolve for each PHP
version independently.

For isolated local verification, build `tests/integration/Dockerfile` with
`--build-arg PHP_VERSION=8.1` (or another supported version), then run the image.
It runs both unit tests and the throwaway Composer integration harness.

## Installing from Packagist

After the package has been submitted to Packagist, consumers can install a
stable release without declaring a VCS repository:

```sh
composer require --dev webong/composer-source-plugin:^1.0
```

### One-time Packagist setup

1. Sign in to [Packagist](https://packagist.org/) with the GitHub account that
   can administer `webong/composer-source-plugin`.
2. Submit `https://github.com/webong/composer-source-plugin` from the Packagist
   package submission page.
3. Enable Packagist's GitHub webhook integration for the repository. Packagist
   then receives every pushed tag and updates package metadata automatically.

The package is public and its root `composer.json` carries the package name,
license, and supported PHP range that Packagist reads. No Packagist credential
is stored in this repository. Release archives also exclude CI, test, and local
container files, while source, `composer.json`, the README, and license remain
available to consumers.

### Creating a release

Run the **Release** workflow from `main` and supply a semantic version such as
`1.0.0`. It validates the tag format and that it does not exist, runs package
validation plus unit and Composer integration tests, creates an annotated
`v1.0.0` tag, and creates the corresponding GitHub release. Composer derives
the stable package version from that tag; Packagist usually makes it visible
within a minute after receiving the webhook.

The workflow only releases the commit checked out from `main`. A failed test or
existing tag stops before anything is published.

The sync runs on `pre-autoload-dump`, immediately before the autoloader is
generated, so a `rebase` always reads post-sync sources. A failing sync warns
and never aborts the install. Note that a Composer plugin cannot act on the
run that first installs it, so the first `composer update` only installs the
plugin.

## Unified configuration

Package source selection and namespace aliases are configured together under
`extra.source-plugin`:

```json
{
    "extra": {
        "source-plugin": {
            "loaders": {
                "zorvia/web-proxy": {
                    "type": "inline",
                    "manifest": "ext/web-proxy/composer.json"
                }
            },
            "aliases": {
                "Webong\\WebhookProxy\\": "Alias\\WebhookProxy\\",
                "webong/web-flow": {
                    "Webong\\WebFlow\\": "Alias\\WebFlow\\",
                    "type": "rebase"
                },
                "webong/web-proxy": {
                    "Webong\\WebProxy\\": "Alias\\WebProxy\\"
                }
            }
        }
    }
}
```

`loaders` controls which implementation wins. `aliases` supports a legacy
flat map and a package-scoped map. A mapping defaults to `simple`, which
creates PHP compatibility aliases. A package-scoped mapping can set
`"type": "rebase"` to opt into source rebasing. The plugin discovers
production classes, interfaces, traits, and enums in installed packages and
writes simple aliases during Composer's autoload generation. It also writes
the production class/interface aliases to
`vendor/composer/source_aliases.php` for runtime integrations.

### Illuminate container aliases

When the consuming application uses Illuminate, the plugin's discovered
service provider reads `source_aliases.php` and registers the compatibility
namespaces with the service container. This means a package can register its
services under its published namespace while the application continues to
resolve its compatibility namespace:

```php
app('App\\WebProxy\\WebProxy');
```

The bridge is optional and is not loaded in non-Illuminate applications. The
Composer plugin remains framework-neutral; only the generated metadata is
shared with the Illuminate integration.

Because Composer plugins execute code during dependency operations, consumers
must explicitly allow the plugin:

```json
{
    "config": {
        "allow-plugins": {
            "webong/composer-source-plugin": true
        }
    }
}
```

## Development

```bash
composer install
composer test
composer test:integration   # requires Docker
```

Requires Composer 2 and PHP 8.1 or newer.
