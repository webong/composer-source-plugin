# Composer Source Plugin documentation

`webong/composer-source-plugin` is a Composer 2 plugin for projects where the
same package can come from more than one place. It answers one question at a
time — *which copy of this package should Composer build?* — and keeps the
answer reproducible through `composer.lock` and `composer-source.lock`.

The plugin is framework-neutral. The optional Illuminate provider only exposes
generated alias metadata to Laravel's container.

## The five features

Each feature hooks into a different part of Composer's lifecycle. They are
related, but they solve different problems.

| Feature | Question it answers | Installed package | Local edits |
| --- | --- | --- | --- |
| [loader](./source-selection.md) | Inline merged manifest or published package? | One of the two | Depends on the winner |
| [workspace](./workspaces.md) | Use my local directory instead of the registry package? | Symlink/copy of the local directory | Live when symlinked |
| [mirror](./mirrors.md) | Keep an editable working copy of a package I still update from upstream? | Published package stays installed | Protected file-by-file |
| [projection](./projections.md) | Keep only selected parts of an installed package? | Same package, pruned | N/A |
| [alias / rebase](./aliases.md) | Expose the same code under an older or different namespace? | Unchanged | N/A |

A package cannot be **mirrored** or **projected** while it is also a loader or
a workspace, because mirrors and projections need the installed package to
remain the source of truth. A workspace and an explicit loader entry may
coexist: an `outline` loader overrides the workspace link. Aliases are
orthogonal and can be combined with any of them.

## Choose a feature

| If you want to… | Use |
| --- | --- |
| Develop several packages in one repository and have Composer link them | `workspaces` |
| Replace a registry package with a local directory, without merge-plugin | `workspaces` |
| Choose between a local merged manifest and the published package | `loaders` |
| Edit a dependency while still receiving upstream releases | `mirrors` |
| Ship one package but install only selected modules | `projections` |
| Keep old class names working after a namespace change | `aliases` |
| Make a mirror working copy itself use the new namespace | `aliases` + `mirrors` with `destination: "mirror"` |

A useful rule of thumb:

- **workspace** is a *source* decision made before resolution;
- **mirror** is a *sync* decision made after installation;
- **projection** is a *content* decision made after installation;
- **aliases** are *metadata* generated after the autoloader is dumped.

## Configuration at a glance

Everything lives under `extra.source-plugin` in the consuming root
`composer.json`:

```json
{
    "require": {
        "webong/composer-source-plugin": "^1.0",
        "acme/core": "@dev",
        "acme/fluent": "^2.0"
    },
    "extra": {
        "source-plugin": {
            "workspaces": ["packages/*"],
            "loaders": {
                "acme/legacy": { "type": "outline" }
            },
            "mirrors": {
                "acme/fluent": { "path": "ext/fluent" }
            },
            "projections": {
                "acme/monolith": { "surfaces": ["core"] }
            },
            "aliases": {
                "acme/fluent": {
                    "Acme\\Fluent\\": "Local\\Fluent\\",
                    "type": "rebase"
                }
            },
            "mirror-state": "lock"
        }
    }
}
```

Full key-by-key reference, defaults, and validation rules:
[configuration](./configuration.md).

## Documentation map

| Page | Contents |
| --- | --- |
| [Configuration](./configuration.md) | Every key, default, conflict rule, and lock file. |
| [Source selection](./source-selection.md) | `loaders`, `inline`/`outline`/`auto`, merge-plugin interplay. |
| [Workspaces](./workspaces.md) | Local package linking, version contract, diagnostics, bootstrap. |
| [Mirrors](./mirrors.md) | Upstream sync, conflicts, project lock, mirror rebasing. |
| [Projections](./projections.md) | Surfaces, pruning, widening, autoload limits, safety. |
| [Aliases and rebases](./aliases.md) | Simple aliases, source rebasing, generated files, Laravel bridge. |
| [Lifecycle](./lifecycle.md) | Which hook runs on which command, and in what order. |
| [Troubleshooting](./troubleshooting.md) | Error messages, causes, and fixes. |
| [Releasing](./releasing.md) | Packagist setup, release workflow, and maintainer checklist. |

## Requirements

- PHP 8.1 or newer.
- Composer 2 with plugin API 2.3 or newer.
- The plugin must be allowed in the consuming project:

  ```json
  {
      "config": {
          "allow-plugins": {
              "webong/composer-source-plugin": true
          }
      }
  }
  ```

- `wikimedia/composer-merge-plugin` is optional. It is only needed when a
  loader's inline source is a separate manifest that must be merged into the
  root package before resolution.

## The first-run rule

A Composer plugin cannot act on the run that installs it, because Composer has
not activated the plugin yet. On a clean project, install the plugin first and
enable the feature on the next run:

```sh
composer update   # installs the plugin
composer update   # applies workspaces, projections, aliases, ...
```

For lock-based installs the same rule applies: the first `composer install`
installs the plugin and the full packages; the second applies projected state.
Mirrors and workspaces are more forgiving because `composer.lock` already
records the source, but a run without the plugin cannot inject anything.

## Conventions used in this documentation

- All configuration paths are relative to the directory containing the root
  `composer.json`, unless stated otherwise.
- `vendor/` means Composer's configured `vendor-dir`.
- "Root package" means the application that requires the plugin.
- "Workspace" is this plugin's local-package concept, not a Composer feature.
- Error text is quoted as the plugin emits it; Composer may wrap long lines.

## Development

```sh
composer install
composer test                 # unit tests
composer test:integration     # Docker-based Composer fixtures
```
