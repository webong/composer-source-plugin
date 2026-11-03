# Lifecycle

This page maps the plugin's hooks to Composer events and explains what runs on
each command. It is the quickest way to answer "why did nothing happen?" and
"which lock file should have changed?".

## Hook map

| Order | Event | Plugin method | What happens |
| --- | --- | --- | --- |
| 1 | Plugin activation | `activate()` | Discover workspaces, validate structural configuration, prepend workspace path repositories. |
| 2 | `pre-pool-create` | `selectPackageSources()` | Validate workspace versions, then remove losing loader candidates from the pool. |
| 3 | `post-package-install` / `post-package-update` | `projectPackage()` | Project a configured package immediately after it is installed. |
| 4 | `pre-autoload-dump` | `beforeAutoloadDump()` | Reconcile projections, then sync mirrors. |
| 5 | `post-autoload-dump` | `generateAliases()` | Generate simple aliases, rebases, and the container alias map. |
| 6 | `post-install-cmd` / `post-update-cmd` | `finalizeProjections()` | Reconcile fresh packages when no autoload dump occurred. |

Supporting hooks:

| Event | What it records |
| --- | --- |
| `pre-update-cmd` | Marks the run as an update, which allows projection and mirror state to advance. |
| `command` | Records whether the command is `reinstall`, which also allows projection state to advance. |

## Ordering rationale

```text
activation
  └── structural validation
pre-pool-create
  └── versions + loader selection
install / update
  └── package events
        └── projection (immediate)
  └── pre-autoload-dump
        ├── projection reconciliation
        └── mirror sync
  └── post-autoload-dump
        └── alias / rebase generation
  └── post-install-cmd / post-update-cmd
        └── projection finalize (fallback)
```

- **Projection before alias generation** means aliases only see retained
  classes.
- **Mirror sync before alias generation** means a `rebase` reads post-sync
  sources.
- **Projection immediately after install** means an `autoload.files` entry that
  points at a deleted file can be removed from in-memory metadata before
  Composer writes the autoloader.
- **Projection reconcile at `pre-autoload-dump`** catches packages that were
  not reinstalled, which is what makes narrowing work.
- **Projection finalize after the command** covers operations that skip the
  autoload dump.

## Command matrix

### `composer update`

| Phase | Behaviour |
| --- | --- |
| Activation | Validates structure and prepends workspace repositories. |
| Resolution | Validates workspace versions; loader decides the winning candidate. |
| Install | Projects configured packages; may write `composer-source.lock`. |
| Pre-autoload dump | Reconciles projections; syncs mirrors. |
| Post-autoload dump | Regenerates aliases and rebases. |

In `mirror-state: lock`, update may advance mirror baselines. Projection state
may advance, including narrowing or widening that a fresh archive supports.

### `composer install`

| Phase | Behaviour |
| --- | --- |
| Activation | Same as update. |
| Resolution | Uses `composer.lock`. Loader selection is applied in memory, but nothing is re-recorded. |
| Install | Projects configured packages only when the committed projection state matches; no state is written. |
| Pre-autoload dump | Reconciles projections; verifies mirror baseline. |
| Post-autoload dump | Regenerates aliases and rebases. |

Install is deliberately read-only with respect to `composer-source.lock`. A
missing projection entry or a changed surface definition is an error telling
you to run `composer update`. In lock mirror mode, a missing or mismatched
baseline skips sync with a warning.

### `composer dump-autoload`

No package is installed and no package event fires.

| Phase | Behaviour |
| --- | --- |
| Activation | Validates structure and prepends workspace repositories. |
| Pre-autoload dump | Reconciles all projected packages against committed state; verifies or applies mirror baselines. |
| Post-autoload dump | Regenerates aliases and rebases. |

Dump-autoload can repair a projected tree for the committed selection, but it
cannot restore deleted files or apply an uncommitted selection change. It does
not write projection state. It can write a local mirror baseline, because local
mirror state is always persisted on sync.

### `composer reinstall <package>`

Reinstall counts as a fresh installation and allows projection state to advance.
In lock mirror mode it verifies the mirror baseline rather than advancing it.

### `composer update --no-install`

Resolution only:

- activation and `pre-pool-create` still run;
- workspaces are validated and loaders are selected in memory;
- no package event fires;
- no autoload dump runs;
- no projection, mirror sync, or alias generation happens;
- no source lock is written.

This is why `--no-install` cannot erase a committed baseline.

### `composer update --dry-run`

Composer dispatches neither package events nor script events. The plugin still
activates and still participates in resolution, so configuration errors and
version mismatches can still fail the run, but nothing is installed, projected,
synced, or written.

### `--no-plugins`

The plugin does not activate at all:

- workspaces are not injected; Composer uses whatever the lock or repositories
  say;
- loaders do not select a winner;
- projections do not run;
- mirrors do not sync;
- aliases are not generated or refreshed.

A committed `composer.lock` can still install recorded workspace path dists,
because Composer handles those directly. That exception does not apply to
configuration changes.

### `--no-scripts`

Package events may still fire, but script events such as `pre-autoload-dump`,
`post-autoload-dump`, `post-install-cmd`, and `post-update-cmd` are suppressed.
Expect projection to be partial and aliases to be stale or absent. Use the
normal command when the plugin's output matters.

### First run

The run that installs the plugin cannot use it. Composer activates plugins
after installing them, so the first `composer update` or `composer install`
only adds the plugin. The next run applies workspaces, projections, mirrors,
and aliases.

## Failure semantics

| Failure | Effect |
| --- | --- |
| Invalid `extra.source-plugin` structure | Plugin activation fails; the command does not continue. |
| Workspace discovery error | Plugin activation fails. |
| Workspace version mismatch | `pre-pool-create` fails before the solver. |
| Projection error (unknown surface, missing state, widening, path safety) | The package operation fails; state is not advanced. |
| Mirror error (unreadable mirror, baseline mismatch, namespace change) | Warning only; the Composer command continues. |
| Alias generation error | `post-autoload-dump` fails; generated files may be stale. |

Mirrors are intentionally non-fatal: a checkout problem should not block an
install. Projections are intentionally fatal: a partially pruned package is not
a safe state to continue from.

## State write matrix

| Command | Projection state | Mirror state (`lock`) | Mirror state (`local`) |
| --- | --- | --- | --- |
| `update` | may advance | may advance | may advance |
| `install` | must match; no write | must match; no write | may advance |
| `dump-autoload` | must match; no write | must match; no write | may advance |
| `reinstall` | may advance | must match; no write | may advance |
| `update --no-install` | no write | no write | no write |
| `update --dry-run` | no write | no write | no write |
| `--no-plugins` | no write | no write | no write |

"Must match" means a missing or different entry is an error for projections and
a warning-and-skip for mirrors.

## What is memoized

Workspace discovery is memoized per `Composer` instance. Project and autoload
events run once per installed package, and re-globbing the tree each time would
turn a large install into thousands of directory reads. A workspace set change
therefore requires a new Composer process, which is the normal case.

Alias configuration is parsed fresh on each generation pass, so changing
`aliases` and running `composer dump-autoload` is enough.
