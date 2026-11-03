# Workspaces

A workspace is a local package directory that becomes the canonical source for
its own package name — the Composer equivalent of an npm workspace link. The
local copy wins over Packagist or a VCS repository, and sibling workspaces can
require each other without being published first.

```text
monorepo/
├── composer.json          # root: declares workspaces
├── packages/
│   ├── core/              # acme/core
│   │   └── composer.json
│   └── util/              # acme/util
│       └── composer.json
└── vendor/
    └── acme/
        ├── core -> ../../packages/core
        └── util -> ../../packages/util
```

There is no manifest rewriting and no `wikimedia/composer-merge-plugin`
requirement. At activation, the plugin prepends one canonical `path`
repository per workspace, so Composer resolves the local directory before any
registry or VCS repository.

## Configuration

```json
{
    "require": {
        "webong/composer-source-plugin": "^1.0",
        "acme/core": "@dev"
    },
    "extra": {
        "source-plugin": {
            "workspaces": ["packages/*", "plugins/*"]
        }
    }
}
```

The list form is shorthand for `include`. The object form adds exclusions and
symlink control:

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
| `exclude` | no | `[]` | Patterns removed after expansion. |
| `symlink` | no | `true` | Composer's path repository `symlink` option. |

Unknown object keys are rejected. `include` must be a non-empty list and
`exclude` must be a list of strings. All patterns must be package-relative.

### Patterns

| Pattern | Meaning |
| --- | --- |
| `packages/*` | Every immediate subdirectory of `packages/`. |
| `packages/*/module` | One extra level. |
| `plugins/[a-z]*` | Character classes, handled by PHP's `glob()`. |
| `packages/**` | Recursively every directory below `packages/`. |
| `packages/core` | One literal directory. |

`**` is only supported as a trailing `/**`. A pattern such as
`packages/**/src` is rejected.

Discovery rules:

- a literal path must exist;
- a glob that matches nothing is ignored;
- a matched directory with no `composer.json` is skipped for a glob and is an
  error for a literal path;
- dot-directories, `vendor`, and `node_modules` are not descended by `/**`;
- duplicate package names are rejected;
- each manifest must declare a valid package `name`;
- `version` is optional, but when present must be a valid Composer version.

`symlink: false` tells Composer to copy the package into `vendor/` instead of
linking it. Edits are then not live; run `composer reinstall <package>` after
changing it. The default `true` is the usual workspace behaviour.

## How resolution works

For every active workspace, the plugin calls Composer's
`prependRepository()` with:

```json
{
    "type": "path",
    "url": "packages/core",
    "options": { "symlink": true }
}
```

`prependRepository()` inserts at index 0, so repositories are added in reverse
declaration order to preserve the configured order. Composer resolves a package
name from the first canonical repository that provides it, which is why the
local copy wins.

The repository URL is relative. That keeps `composer.lock` portable:

```json
{
    "name": "acme/core",
    "version": "2.0.0",
    "dist": {
        "type": "path",
        "url": "packages/core"
    }
}
```

A fresh clone with the committed lock can recreate the link without a
`repositories` entry in `composer.json`. The absolute install path is not
stored.

Nothing is written to the consuming `composer.json`. The published package
remains the source of truth for consumers outside the monorepo.

## Version contract

Composer has no `workspace:` protocol. Its parser rejects that literal before
plugins activate, so the plugin cannot intercept it. The equivalent contract is
explicit:

| Root requirement | Workspace `version` | Result |
| --- | --- | --- |
| `"acme/core": "@dev"` | optional | Local workspace always satisfies it. |
| `"acme/core": "*"` | optional | Local workspace always satisfies it. |
| `"acme/core": "dev-main"` | optional | Branch constraint, skipped by version validation. |
| `"acme/core": "^2.0"` | required, must satisfy | Linked only when the declared version matches. |
| `"acme/core": "^2.0"` | missing | Actionable error. |
| `"acme/core": "^9.0"` | `2.0.0` | Actionable error with both versions. |

The following constraints skip local version validation: an empty constraint,
`self.version`, anything starting with `@` (such as `@dev`), exactly `*`, and
anything starting with `dev-`. Stability flags on a semver constraint, such as
`^2.0@dev`, are still validated as semver.

Validation covers:

- root `require` and `require-dev` entries that point at a workspace;
- each workspace's own `require` entries that point at another workspace.

`require-dev` inside a workspace is not checked. External requirements are
ignored; only edges between configured workspaces are validated.

A mismatch fails before the solver runs:

```text
Workspace [acme/core] is 2.0.0, but the root requires ^9.0. Align the requirement or the workspace version.
Workspace [acme/core] does not declare a version, but the root requires ^2.0. Add "version" to packages/core/composer.json, or require it as @dev or *.
```

This is not a silent fallback. A canonical path repository whose local version
does not satisfy the constraint would otherwise fall through to the published
package, which is exactly the surprise the check prevents.

## Inspecting the resolved set

```sh
composer source:workspaces
```

```text
2 workspace(s): 2 linked, 0 outline.
  acme/core     packages/core    2.0.0     linked
      requires acme/util ^1.0
  acme/util     packages/util    1.2.0     linked
```

Each row shows the package, relative path, declared version (or `dev`), and mode:

- `linked`: a path repository was prepended;
- `outline`: an explicit `loaders` entry routed the package back to the
  repository version.

Only requirements between workspaces are printed.

Add `--json` for tooling:

```json
{
    "workspaces": [
        {
            "package": "acme/core",
            "path": "packages/core",
            "version": "2.0.0",
            "mode": "linked",
            "symlink": true,
            "requires": { "acme/util": "^1.0" }
        }
    ],
    "problems": []
}
```

The command exits non-zero when any version problem is found and prints all of
them in one pass. Structural discovery errors (bad patterns, duplicate names,
missing manifest) also exit non-zero. This is useful when `composer update`
would stop at the first problem.

The command works even when version validation would otherwise block
resolution, because version checks run at `pre-pool-create` rather than
activation.

## Opting a package back to the published version

An explicit loader entry of type `outline` wins over workspace injection:

```json
{
    "extra": {
        "source-plugin": {
            "workspaces": ["packages/*"],
            "loaders": {
                "acme/core": { "type": "outline" }
            }
        }
    }
}
```

That package is installed from its repository instead of being linked, and its
local version is not validated. The package still counts as a loader for
conflict detection, so it cannot also be mirrored or projected.

## Bootstrapping

A Composer plugin cannot act on the run that installs it. Install the plugin
first, then enable workspaces:

```sh
composer update   # installs webong/composer-source-plugin
composer update   # links the workspaces
```

On the first run, a package that exists only as a workspace cannot be resolved;
Composer has no repository for it yet.

For consumers and CI, a committed `composer.lock` is enough: it records the
workspace packages with a relative `path` dist, and `composer install` can
recreate the links from the lock. This works even with `--no-plugins` **for the
recorded set**, because Composer installs the locked path dists directly.

Do not rely on `--no-plugins` for workspace changes. Without the plugin,
Composer does not inject the path repositories, so any resolution falls back to
the repository versions. Run `composer update` with plugins enabled whenever
the workspace set or versions change.

A missing `allow-plugins` entry has the same effect as `--no-plugins`: the
plugin does not activate.

## Interaction with other features

- **Aliases** are allowed. They run after installation and see the linked
  source.
- **Mirrors** are rejected. A mirror needs the remote package installed; a
  workspace replaces it.
- **Projections** are rejected. A projection prunes an installed package; a
  workspace is the local source of truth.
- **Loaders** arbitrate candidates. A workspace is treated as an implicit
  `inline` loader for conflict validation and can be overridden by `outline`.

## Limits

- **No `workspace:` protocol.** Composer parses it before plugins load.
- **No nested workspace declaration.** A trailing `/**` covers the tree-walking
  case without a second config layer.
- **No task runner.** The plugin does not run scripts, bump versions, or
  publish packages.
- **No per-workspace configuration.** All matched packages share `symlink` and
  exclusions.
- **Glob typos degrade quietly.** A pattern that matches nothing is ignored by
  design; use literal paths where a typo should be loud.
- **Path dists, not registry versions.** A workspace is a working tree, not an
  immutable release.
- **`--no-plugins` is not a supported resolution path.** Recorded lock entries
  are the only exception.

## Design notes

These notes explain why the implementation looks the way it does.

### Path repositories instead of loaders + merge-plugin

Loaders arbitrate between candidates that are already in the pool. A workspace
needs to introduce a candidate for a package the registry may not serve at all,
without merging autoload into the root package. A canonical path repository
does that in one step and removes the merge-plugin failure mode (duplicate
namespace exposure) by construction.

Verified with real Composer 2:

- repository order decides the winner, so `prependRepository()` beats Packagist
  and VCS repositories declared in `composer.json`;
- a relative path URL produces a relative `dist.url` in `composer.lock`;
- `composer install` recreates the link from a committed lock with no
  repositories declared.

### Validation timing

Structural problems (bad patterns, duplicate names, mirror/projection
conflicts) fail at plugin activation, before a command mutates the dependency
pool. Version compatibility is checked at `pre-pool-create`, because it only
matters when a pool is being built, and keeping it out of activation is what
lets `composer source:workspaces` report every problem instead of dying on the
first.

An explicit `outline` loader removes the workspace from the active set before
both validation and repository injection.

### Deliberate omissions

- **No extra lock state.** `composer.lock` already records the path dist, and
  workspace contents are the working tree. A `composer-source.lock` entry would
  duplicate it and churn on every local edit.
- **No `workspace:` syntax.** Impossible to intercept, as above.
- **No task runner / version bumping / publish orchestration.** Out of scope.

## Verified behaviour

The integration suite (`tests/integration/workspaces.sh`) runs real Composer
commands and covers:

- linking one and several workspaces;
- sibling requirements resolving locally;
- relative `dist.url` in the lock and a fresh clone installing from it;
- root and sibling version mismatches, including the diagnostic command;
- an `outline` override installing the repository package;
- workspace conflicts with mirrors and projections;
- the `--json` output and exit codes.
