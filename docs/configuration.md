# Configuration reference

Every feature is configured under `extra.source-plugin` in the consuming root
`composer.json`. The plugin reads this section through one class,
`SourcePluginConfig`, so validation and conflict detection are consistent.

```json
{
    "extra": {
        "source-plugin": {
            "loaders": {},
            "workspaces": [],
            "mirrors": {},
            "projections": {},
            "aliases": {},
            "mirror-state": "local"
        }
    }
}
```

All keys are optional. Unknown keys inside `extra.source-plugin` are ignored;
unknown keys inside a feature entry are usually rejected. All package names
should be lowercase `vendor/package` names.

## Key overview

| Key | Shape | Default | Purpose |
| --- | --- | --- | --- |
| `loaders` | map of package → loader object | `{}` | Choose inline or outline before resolution. |
| `workspaces` | list of globs, or object | `[]` | Link local package directories as canonical sources. |
| `mirrors` | map of package → path or object | `{}` | Keep an editable copy of an installed dependency. |
| `projections` | map of package → `{surfaces: []}` | `{}` | Keep selected surfaces from an installed package. |
| `aliases` | flat or package-scoped map | `{}` | Generate compatibility aliases or rebase source. |
| `mirror-state` | `local` or `lock` | `local` | Where mirror sync baselines live. |

Feature interactions:

```text
                         before resolution        after installation
                         -----------------        ------------------
loaders / workspaces  →  choose the package   →   (nothing)
mirrors               →  (nothing)            →   sync working copy
projections           →  (nothing)            →   prune installed package
aliases               →  (nothing)            →   generate metadata
```

## `loaders`

Selects which candidate wins when a package exists both as a local merged
manifest and as a published package.

```json
{
    "loaders": {
        "webong/web-proxy": {
            "type": "auto",
            "path": "ext/web-proxy",
            "manifest": "ext/web-proxy/composer.json"
        }
    }
}
```

| Field | Required | Meaning |
| --- | --- | --- |
| `type` | no | `inline`, `outline`, or `auto`. Default `auto`. |
| `path` | no | Local directory used to recognise a path candidate and to remove merged autoload entries when an outline wins. |
| `manifest` | no | Local manifest path. `auto` uses it to decide whether the inline side exists; `outline` uses it to remove merged requirements. |

`inline` prefers a candidate whose dist type is `path`. `outline` prefers a
non-path candidate. `auto` prefers a path candidate when present, otherwise an
outline candidate. An unrecognised `type` is treated as `auto`.

If `inline` (or `auto` with an existing `manifest`) chooses the inline side and
no path candidate exists, the package is removed from the pool and the root
requirement is removed, which is the expected shape when
`wikimedia/composer-merge-plugin` has already merged the local manifest into the
root package.

When `outline` wins, the plugin removes the merged manifest's autoload entries
under `path` and its requirements from the root package, so the two
implementations do not expose the same namespaces. See
[source selection](./source-selection.md).

## `workspaces`

A workspace is a local package directory that becomes the canonical source for
its own package name.

```json
{
    "workspaces": ["packages/*", "plugins/*"]
}
```

The object form adds exclusions and controls symlinking:

```json
{
    "workspaces": {
        "include": ["packages/*"],
        "exclude": ["packages/legacy"],
        "symlink": true
    }
}
```

| Field | Required | Default | Meaning |
| --- | --- | --- | --- |
| `include` | yes (object form) | — | Non-empty list of package globs. |
| `exclude` | no | `[]` | Globs removed after expansion. |
| `symlink` | no | `true` | Passed to Composer's `path` repository. `false` copies instead of symlinking. |

Supported patterns:

- `*` matches within one path segment;
- `?` matches one character within a segment;
- `[...]` character classes work through PHP's `glob()`;
- `**` is allowed only as a trailing `/**` and descends recursively.

A literal path that does not exist is an error. A glob that matches nothing is
ignored. A matched directory without `composer.json` is skipped for a glob and
is an error for a literal path. Dot directories, `vendor`, and `node_modules`
are not descended.

Each workspace must declare a valid package `name`. `version` is optional and,
when present, must be a valid Composer version. Duplicate package names are
rejected.

See [workspaces](./workspaces.md).

## `mirrors`

Keeps an editable working copy of an installed package while the published
package stays installed and updateable.

```json
{
    "mirrors": {
        "acme/fluent": "ext/fluent",
        "webong/cogent": {
            "path": "ext/cogent",
            "origin": "remote"
        }
    }
}
```

| Field | Required | Default | Meaning |
| --- | --- | --- | --- |
| `path` | yes | — | Working-copy directory, relative to the root or absolute. |
| `origin` | no | `remote` | Only `remote` is supported: sync from the installed package. |

A package cannot be both mirrored and a loader, and a workspace counts as a
loader for this rule. A package also cannot be both mirrored and projected.
See [mirrors](./mirrors.md).

## `mirror-state`

```json
{
    "mirror-state": "lock"
}
```

| Value | Behaviour |
| --- | --- |
| `local` (default) | Baseline lives in `<mirror>/.source-plugin/sync.json`. |
| `lock` | Baseline lives in `composer-source.lock` beside the manifest. |

Switching from `local` to `lock` migrates a recognised legacy baseline and
removes it only after the project lock is saved. Switching back to `local` does
not export the lock state. Any other value is rejected at activation.

## `projections`

Selects named surfaces declared by an installed package's author.

```json
{
    "projections": {
        "acme/monolith": {
            "surfaces": ["core", "javascript"]
        }
    }
}
```

| Field | Required | Meaning |
| --- | --- | --- |
| `surfaces` | yes | Non-empty list of surface names. Duplicates collapse; names are sorted. |

The package must be installed and must declare `extra.source-plugin.surfaces` in
its own root `composer.json`. The plugin package itself cannot be projected.
Only the `surfaces` key is accepted. A package cannot be both projected and
mirrored or loadable. See [projections](./projections.md).

## `aliases`

Two forms are accepted. The flat form is a legacy convenience and applies to
every installed package:

```json
{
    "aliases": {
        "Acme\\Library\\": "Legacy\\Library\\"
    }
}
```

The package-scoped form groups mappings and accepts rebase options:

```json
{
    "aliases": {
        "acme/fluent": {
            "Acme\\Fluent\\": "Local\\Fluent\\",
            "type": "rebase"
        }
    }
}
```

| Field | Applies to | Default | Meaning |
| --- | --- | --- | --- |
| `type` | package-scoped | `simple` | `simple` creates `class_alias` compatibility names; `rebase` rewrites source. |
| `copy` | rebase | `package` | `package` copies the whole package; `autoload` copies PSR-4 source, `autoload.files`, and `include`. |
| `include` | rebase | `[]` | Extra package-relative paths for `copy: "autoload"`. No globs. |
| `exclude` | rebase | `[]` | Package-relative subtrees removed from the copy or rebase. |
| `destination` | rebase | `generated` | `generated` writes under `vendor/composer/rebased/`; `mirror` rewrites the configured mirror. |

Every other key in a package-scoped entry is a `source\prefix` →
`target\prefix` mapping. At least one mapping is required. A source prefix may
be configured only once across the whole alias map. `copy`, `include`, and
`exclude` are rejected on a `simple` alias.

Alias configuration is validated when aliases are generated (after the
autoloader is dumped), not at plugin activation. See [aliases](./aliases.md).

## Cross-feature rules

The plugin rejects contradictory combinations at activation, before any command
changes the dependency pool:

| Combination | Result | Why |
| --- | --- | --- |
| `mirrors` + `loaders` | error | A mirror needs the remote package installed; a loader removes it. |
| `mirrors` + `projections` | error | A mirror syncs the complete package; a projection deletes files. |
| `projections` + `loaders` | error | A loader chooses a candidate before resolution; a projection acts after installation. |
| `workspaces` + `mirrors` | error | A workspace is an implicit inline loader. |
| `workspaces` + `projections` | error | Same reason. |
| `aliases` + any | allowed | Aliases only transform metadata or copied source. |

Exact messages:

```text
Package [acme/fluent] cannot be both mirrored and loadable: a mirror needs the remote package installed, while a loader removes it from the pool. Configure one or the other.
Package [acme/monolith] cannot be both projected and mirrored: a mirror synchronizes the full package into a working copy, while a projection removes files from the installed package. Configure one or the other.
Package [acme/monolith] cannot be both projected and loadable: a loader chooses a source candidate before resolution, while a projection acts on the installed package after download. Configure one or the other.
```

An explicit `loaders` entry of type `outline` disables the workspace link for
that package, but the package still counts as a loader for conflict detection.
That means a workspace with an `outline` override still cannot be mirrored or
projected.

Precedence between workspace injection and an explicit loader:

| Explicit loader | Workspace repository | Installed source |
| --- | --- | --- |
| absent | prepended | The local directory (symlink by default). |
| `outline` | not prepended | The repository version. |
| `inline` or `auto` | prepended | The local directory, subject to candidate selection. |

## State files

| File | Written by | Commit? | Purpose |
| --- | --- | --- | --- |
| `composer.lock` | Composer | Usually yes | Records workspace path dists and normal package versions. |
| `composer-source.lock` | Plugin | Yes when used | Mirror baselines and projection state. |
| `<mirror>/.source-plugin/sync.json` | Plugin | Yes in `mirror-state: local` | Per-mirror baseline. |
| `vendor/<pkg>/.source-plugin/projection.json` | Plugin | No | Marker recording what a projection removed. |
| `vendor/composer/namespace_aliases.php` | Plugin | No | Generated `class_alias` calls. |
| `vendor/composer/namespace_rebases.php` | Plugin | No | Generated rebase autoloader. |
| `vendor/composer/source_aliases.php` | Plugin | No | Generated class/interface map for Illuminate. |
| `vendor/composer/rebased/` | Plugin | No | Generated rebased source trees. |

`composer-source.lock` is named after the manifest: `composer.json` produces
`composer-source.lock`, and `COMPOSER=other.json` produces `other-source.lock`
beside it. The file has a `version`, a `mirrors` map, and a `projections` map.
Writes are atomic and preserve the other feature's entries.

Do not commit generated files under `vendor/`. They are rebuilt by
`composer install`, `composer update`, or `composer dump-autoload` whenever the
plugin is active.

## Validation timing

| Phase | What is checked |
| --- | --- |
| Plugin activation | `mirror-state` value, mirror/loader conflicts, projection conflicts and shapes, workspace patterns and package names. |
| `pre-pool-create` | Workspace versions against root and sibling requirements, before the solver sees the pool. |
| Package install/update | Projection surfaces, marker, widening feasibility, and committed state. |
| `pre-autoload-dump` | Projection reconciliation and mirror sync. |
| `post-autoload-dump` | Alias configuration and generated files. |

This split is intentional. Structural errors should stop the command early;
version errors only matter when a pool is built; projection and mirror errors
need an installed package to reason about.

## Complete example

A monorepo that links local packages, mirrors one upstream dependency, projects
another, and keeps an old namespace working:

```json
{
    "name": "acme/application",
    "require": {
        "webong/composer-source-plugin": "^1.0",
        "acme/core": "@dev",
        "acme/fluent": "^2.0",
        "acme/monolith": "^1.0"
    },
    "config": {
        "allow-plugins": {
            "webong/composer-source-plugin": true
        }
    },
    "extra": {
        "source-plugin": {
            "workspaces": ["packages/*"],
            "mirrors": {
                "acme/fluent": { "path": "ext/fluent" }
            },
            "projections": {
                "acme/monolith": { "surfaces": ["core", "javascript"] }
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

Remember the first-run rule: install the plugin before enabling the rest.
