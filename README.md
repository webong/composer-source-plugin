# Composer Source Plugin

**One package name, one deliberate source.**

`webong/composer-source-plugin` is a Composer 2 plugin for monorepos,
long-lived forks, and packages that ship optional modules. It decides where each
package comes from, keeps local work editable, and records reproducible state
in Composer's lock file plus a small companion lock when a feature needs it.

Works with PHP 8.1+ and Composer plugin API 2.3+.

## Why this exists

Composer assumes a package name has one obvious source. Real projects often
have two:

- a local package and its published release;
- a monorepo package that is not on Packagist yet;
- a dependency you need to patch while still tracking upstream;
- one package that ships every optional module;
- a namespace migration where old code still has to work.

Those cases usually end with editing `vendor/`, committing generated code, or
asking every developer to remember a different setup. This plugin makes them
explicit in `extra.source-plugin`.

## What it does

| If you need to… | Feature | What the plugin does |
| --- | --- | --- |
| Link local packages like npm workspaces | [Workspaces](docs/workspaces.md) | Prepends `path` repositories so local packages win over Packagist or VCS. |
| Choose between a merged local manifest and the published package | [Source selection](docs/source-selection.md) | Removes the losing candidate before Composer resolves the pool. |
| Patch a dependency and still receive upstream releases | [Mirrors](docs/mirrors.md) | Syncs upstream into an editable working copy, file by file, without overwriting local edits. |
| Install only selected parts of a package | [Projections](docs/projections.md) | Prunes an installed package to author-declared surfaces and records the selection. |
| Keep old namespaces working | [Aliases and rebases](docs/aliases.md) | Generates compatibility aliases, rebased source, and Laravel/Symfony container bridges. |

A package cannot be **mirrored** or **projected** while it is also a loader or a
workspace; those features compete over where the installed code comes from.
Aliases compose with all of them.

## Install

```sh
composer require --dev webong/composer-source-plugin
```

Allow the plugin in the consuming project:

```json
{
    "config": {
        "allow-plugins": {
            "webong/composer-source-plugin": true
        }
    }
}
```

> **First run:** Composer cannot use a plugin on the run that installs it.
> Run `composer update` or `composer install` again to apply the configuration.
> See the [first-run rule](docs/README.md#the-first-run-rule).

## Quick start: link a monorepo

With the plugin installed and allowed, add the workspace entries to the root
manifest (the plugin requirement is included for completeness):

```json
{
    "require": {
        "webong/composer-source-plugin": "^1.0",
        "acme/core": "@dev"
    },
    "extra": {
        "source-plugin": {
            "workspaces": ["packages/*"]
        }
    }
}
```

Run `composer update`. `vendor/acme/core` then points at `packages/core`,
sibling workspaces can require each other locally, and `composer.lock` records
a relative path dist that stays portable:

```sh
composer source:workspaces

2 workspace(s): 2 linked, 0 outline.
  acme/core     packages/core    2.0.0     linked
      requires acme/util ^1.0
```

No manifest rewriting and no merge plugin required. Full guide:
[Workspaces](docs/workspaces.md).

## Quick start: choose a source

When `wikimedia/composer-merge-plugin` merges a local manifest, `loaders` picks
which copy survives:

- `inline` prefers the local path candidate;
- `outline` prefers the published package;
- `auto` decides from the configured manifest and available candidates.

```json
{
    "require": {
        "webong/composer-source-plugin": "^1.0",
        "wikimedia/composer-merge-plugin": "^2.1",
        "zorvia/web-proxy": "@dev"
    },
    "extra": {
        "merge-plugin": {
            "include": ["ext/web-proxy/composer.json"]
        },
        "source-plugin": {
            "loaders": {
                "zorvia/web-proxy": {
                    "type": "auto",
                    "path": "ext/web-proxy",
                    "manifest": "ext/web-proxy/composer.json"
                }
            }
        }
    }
}
```

When `outline` wins, the plugin also removes the merged manifest's autoload
entries and requirements from the root package, so both implementations do not
expose the same namespace. Full guide:
[Source selection](docs/source-selection.md).

Need namespace compatibility too? The `"aliases"` key generates aliases or
rebased source after the autoloader is dumped. See
[Aliases and rebases](docs/aliases.md).

## More recipes

| Goal | Configuration |
| --- | --- |
| Develop a fork while tracking upstream | [`mirrors`](docs/mirrors.md) plus an optional `rebase` alias |
| Ship one package, install selected modules | [`projections`](docs/projections.md) with author-declared `surfaces` |
| Replace an old namespace everywhere | [`aliases`](docs/aliases.md) with `"type": "rebase"` |
| Inspect workspace versions and overrides | `composer source:workspaces [--json]` |

## How it works

- **Workspaces** add canonical `path` repositories at plugin activation.
- **Loaders** filter candidates at `pre-pool-create`, before the solver runs.
- **Mirrors** sync at `pre-autoload-dump`; conflicts keep your file and write
  the upstream copy beside it as `<file>.upstream`.
- **Projections** prune the installed tree after download and commit the
  selection to `composer-source.lock`.
- **Aliases** are generated at `post-autoload-dump`; a projection limits which
  installed files can be aliased.

Nothing is written to your `composer.json` at runtime. The full event map is in
[Lifecycle](docs/lifecycle.md).

## Documentation

| Guide | What it covers |
| --- | --- |
| [Documentation index](docs/README.md) | Feature chooser, mental model, first-run rules. |
| [Configuration](docs/configuration.md) | Every `extra.source-plugin` key, default, and conflict rule. |
| [Source selection](docs/source-selection.md) | `inline` / `outline` / `auto` and merge-plugin interplay. |
| [Workspaces](docs/workspaces.md) | Local package linking, version contract, diagnostics. |
| [Mirrors](docs/mirrors.md) | Upstream sync, conflicts, baselines, mirror rebasing. |
| [Projections](docs/projections.md) | Surfaces, pruning, widening, autoload limits. |
| [Aliases and rebases](docs/aliases.md) | Compatibility aliases, source rebasing, Laravel and Symfony bridges. |
| [Lifecycle](docs/lifecycle.md) | Which hook runs on which command. |
| [Troubleshooting](docs/troubleshooting.md) | Error messages, causes, and fixes. |
| [Releasing](docs/releasing.md) | Packagist setup and the release workflow. |

## Compatibility

- PHP 8.1 or newer.
- Composer 2 with plugin API 2.3 or newer.
- `wikimedia/composer-merge-plugin` is optional; it is only needed when a
  loader's inline source is a separate manifest.
- Optional Illuminate and Symfony integration for container aliases; the
  Composer plugin itself is framework-neutral.

## Development

```sh
composer install
composer test                 # unit tests
composer test:integration     # Docker-based Composer fixtures
```

For an isolated runtime, build `tests/integration/Dockerfile` with
`--build-arg PHP_VERSION=8.1` (or another supported version) and run the image;
it executes both unit tests and the Composer integration harness.

CI runs unit tests and real Composer fixtures on PHP 8.1 through 8.5, with
Composer 2.3 and current Composer 2. Release archives exclude CI, test, and
local container files.

Licensed under MIT. See [LICENSE](LICENSE).
