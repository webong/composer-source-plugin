# `workspaces`: implementation notes

Status: implemented. User-facing documentation lives in the README; this file
records why the design looks the way it does and what was verified.

## What shipped

| Piece | Purpose |
| --- | --- |
| `WorkspaceDefinition` | One local package: name, relative path, optional version, its `require` map, symlink flag. |
| `WorkspaceConfiguration` | Reads `extra.source-plugin.workspaces`, expands globs, validates names/versions, filters `outline` overrides, checks semver. |
| `WorkspaceRepository` | Prepends one canonical `path` repository per active workspace. |
| `ComposerSourcePlugin::activate()` | Discover → validate structural conflicts → prepend repositories. |
| `SourcePluginConfig::assertValid()` | Workspaces join the loader set, so `mirrors`/`projections` conflicts reuse existing errors. |
| `ComposerSourcePlugin::selectPackageSources()` | Checks workspace versions at `pre-pool-create`, before the solver runs. |
| `tests/integration/workspaces.sh` | 28 end-to-end assertions against real Composer runs, including the diagnostic command. |
| `Command\WorkspacesCommand` | `composer source:workspaces [--json]`, the resolved graph and every version problem. |

## Why path repositories instead of loaders + merge-plugin

Loaders arbitrate between candidates that are already in the pool. A workspace
needs to *introduce* a candidate for a package that the registry may not serve
at all, without merging autoload into the root package. A canonical path
repository does that in one step, and it removes the merge-plugin failure mode
(duplicate namespace exposure) by construction.

Verified with real Composer 2.10:

- repository order decides the winner, so `prependRepository()` beats Packagist
  and VCS repositories declared in `composer.json`;
- a relative `path` URL produces a relative `dist.url` in `composer.lock`;
- `composer install` recreates the link from a committed lock with **no
  repositories declared and `--no-plugins`**, which is the bootstrap answer.

## Why the version contract is strict

Composer parses root constraints before plugins activate, so `"workspace:*"` is
rejected by the parser and cannot be intercepted. The equivalent is `@dev` or
`*` (local always wins), with semver ranges validated against the workspace's
own `version`. A mismatch fails with both numbers in the message.

Two behaviours worth remembering:

- a canonical path repository whose local version does **not** satisfy the
  constraint falls through to the next repository, which would silently install
  the published package — `assertSatisfiable()` runs first specifically to turn
  that into an error;
- an explicit `loaders` entry of type `outline` deliberately opts a package back
  to the published version, so `active()` removes it before both validation and
  repository injection.

Validation timing is deliberate: structural problems (bad patterns, duplicate
names, mirror/projection conflicts) fail at activation, while version
compatibility is checked at `pre-pool-create`. Version checks only matter when a
pool is being built, and keeping them out of activation is what lets
`composer source:workspaces` report all of them instead of dying on the first.

## Deliberate omissions

- **No extra lock state.** `composer.lock` already records the path dist, and
  workspace contents are the working tree. A `composer-source.lock` entry would
  duplicate it and would churn on every local edit.
- **No `workspace:` syntax.** Impossible to intercept, as above.
- **No task runner / version bumping / publish orchestration.** Out of scope.
- **No nested workspace declaration.** A trailing `/**` covers the tree-walking
  case without a second config layer.

## Known limits

- `--no-plugins` (or an unset `allow-plugins`) silently reverts to repository
  versions. This is tested, not just documented.
- A glob that matches nothing is ignored, so a mistyped glob degrades to the
  registry. Literal paths must exist, which catches the common typo.
- The first ever `composer update` on a repository with no lock cannot resolve
  workspace-only packages; install the plugin first. A committed lock removes
  the problem for `composer install`.
