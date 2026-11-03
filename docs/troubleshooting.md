# Troubleshooting

Start with the plugin itself:

```sh
composer show webong/composer-source-plugin
composer config allow-plugins.webong/composer-source-plugin
```

If the package is installed but `allow-plugins` is not `true`, nothing in this
documentation runs. A first run that installs the plugin also does nothing by
design; run Composer again.

## Quick diagnosis

| Symptom | First check |
| --- | --- |
| Nothing happens at all | `allow-plugins`, first run, `--no-plugins`, `--no-scripts`. |
| Workspace installed from the registry | `composer source:workspaces`, then `composer update <pkg>`. |
| Projection did not run | Plugin active? Full package still present? State missing? |
| Alias missing | Run `composer dump-autoload -vvv`; check prefix and generated files. |
| Mirror did not sync | Package installed? Autoload dumped? Check the warning. |
| State mismatch on install | Run `composer update` to advance committed state. |

Useful commands:

```sh
composer source:workspaces          # workspace graph and version problems
composer source:workspaces --json   # machine-readable form
composer show acme/core             # dist/source type and version
composer dump-autoload -vvv         # regenerate aliases with detail
composer diagnose                  # general Composer health
```

## The plugin is not running

**Symptoms**

- `vendor/acme/core` is a normal directory, not a symlink.
- Projected packages still contain every file.
- No `vendor/composer/namespace_aliases.php`.
- Mirrors are not updated.

**Causes and fixes**

| Cause | Fix |
| --- | --- |
| `allow-plugins` is missing or false | Add `"webong/composer-source-plugin": true` under `config.allow-plugins`. |
| First run installed the plugin | Run `composer update` or `composer install` again. |
| `--no-plugins` | Re-run without it. |
| `--no-scripts` suppressed plugin script hooks | Re-run normally. |
| Plugin not required | `composer require --dev webong/composer-source-plugin`. |
| Composer 2.3+ / PHP 8.1+ not met | Check `composer diagnose` and the platform requirements. |

## Workspaces

### A workspace is not linked

1. Run `composer source:workspaces`. It lists only discovered workspaces.
2. If the package is missing from the list, fix the glob. A glob that matches
   nothing is ignored by design; use a literal path while debugging.
3. If the package is listed as `outline`, check for an explicit `loaders` entry
   of type `outline`.
4. If the package is listed as `linked` but `composer show` reports a registry
   version, the existing `composer.lock` still pins the old source. Re-resolve:

   ```sh
   composer update acme/core
   ```

   `composer install` never changes a locked source.

### `Workspace [x] does not exist`

A literal path in `workspaces` must exist. Either create it or use a glob.

### `Workspace [x] has no composer.json`

A literal workspace directory must contain a manifest. For a glob match, the
directory is skipped instead.

### `Workspace [x] is declared by both a and b`

Two directories declare the same package name. Remove one from the workspace
set, or rename the package.

### `Workspace pattern [x] may only use "**" as a trailing segment`

Use `packages/**`, not `packages/**/src`.

### `Workspace [x] is 2.0.0, but the root requires ^9.0`

The local version and the constraint disagree. Either change the root
requirement, change `version` in the workspace manifest, or require the
workspace as `@dev` / `*` if version checking is not wanted.

### `Workspace [x] does not declare a version`

A semver constraint requires a local version. Add `version` to the workspace's
`composer.json`, or relax the requirement to `@dev` or `*`.

### `workspace:*` is rejected by Composer

There is no `workspace:` protocol. Composer parses root constraints before
plugins activate. Use `@dev` and the version contract described in
[workspaces](./workspaces.md#version-contract).

### A workspace-only package cannot be resolved on the first run

The plugin is installed by that same run and cannot inject repositories yet.
Install the plugin first, then run update again. A committed lock avoids the
problem for later installs.

## Source selection

### Both copies of the package are installed

The loader did not remove the losing candidate. Confirm the package key matches
the installed `name` exactly, that the loader value is an object, and that
`path`/`manifest` point at the real files. Run with `-vvv` and look for
`inline source selected` or `outline source selected`.

### The package disappeared from the pool

An `inline` loader with no path candidate removes the package and its root
requirement. That is correct when a merged manifest provides the code. If the
merged manifest is not actually present, switch to `outline` or remove the
loader entry.

### The outline winner still exposes the old namespace

Cleanup uses `path` to strip root autoload entries and `manifest` to strip
merged requirements. If either is missing or wrong, set both. Then re-run
`composer update` so the root package is rebuilt.

## Projections

### The package is not projected at all

Check, in order:

1. the plugin is active (see above);
2. the package is actually installed;
3. `projections` uses the installed package name;
4. the installed package declares `extra.source-plugin.surfaces`;
5. the first-run rule has been satisfied.

### `Package [x] does not declare any surfaces ...`

The installed package's root `composer.json` has no surfaces. Confirm the
author configuration is present in the installed archive, not only in a build
script.

### `Projection [x] selects unknown surface [y]; available surfaces: ...`

The selected name does not exist. The error lists what is available. Check
spelling and case.

### `No projection state for [x] exists in ...`

This happens on `composer install` or `dump-autoload` before the projection has
ever been recorded. Run a full update with plugins enabled:

```sh
composer update
```

Then commit `composer-source.lock` and reinstall.

### `Projection state for [x] does not match ... (key differs)`

The committed state and the current configuration, package version, or surface
definitions differ. Either restore the committed configuration or advance the
state:

```sh
composer update
```

A changed unselected surface also changes `definition_digest`; that is
intentional.

### `Cannot widen projection ... composer reinstall x`

A newly selected surface needs files that a previous projection deleted. Run:

```sh
composer reinstall acme/monolith
```

or a compatible `composer update`. A failed widening leaves the previous state
untouched.

### The installed package path is a symlink

Projection refuses symlinked installations. For a path repository, set
`"symlink": false` so Composer copies the package. A workspace cannot be
projected at all.

### `composer dump-autoload` did not restore removed files

It cannot. Dump-autoload can repair the tree for the committed selection, but
only a reinstall or update restores the full archive. Use `composer reinstall`.

### A source installation warns about deleted tracked files

Prefer dist installations. A projected source checkout has deleted tracked
files, and Composer may refuse a later source update. The warning tells you to
run `composer reinstall`.

### `the package parent directory is not writable`

Projection stages the new tree beside the package before swapping it in.
`vendor/` and the package parent must be writable by the Composer process.

## Mirrors

### The mirror is empty or stale

- Is the package installed? A mirror never installs anything.
- Did the command dump the autoloader? `--no-install` and `--dry-run` do not.
- Is the plugin active?
- Is the mirror path correct relative to the project root?

The sync runs on `pre-autoload-dump`, so `composer dump-autoload` is a quick
way to re-run it.

### `Could not mirror x into y: ...`

This is a warning, not a fatal error. The command continues. Read the inner
message:

| Inner message | Meaning |
| --- | --- |
| `Mirror source for [x] is not installed at [...]` | The package is not installed or the install path is wrong. |
| `Mirror baseline is missing or does not match the installed reference; run composer update to sync it.` | Lock mode sees a different installed reference. Run update. |
| `Mirror namespace mapping differs from its locked baseline; migrate the working copy before changing mappings.` | A rebase mapping changed against the lock. Restore the mapping or migrate deliberately. |
| `Cannot migrate invalid legacy mirror state: ...` | The old `.source-plugin/sync.json` is unreadable. Restore or remove it after backing up. |
| `Legacy mirror state belongs to a different package: ...` | A stale baseline from another package occupies the mirror path. Move it aside. |

### Local edits were overwritten

The most common cause is a missing baseline. Without `sync.json` (or a lock
entry), an existing file is treated as unbased: current upstream becomes the
baseline, and later upstream changes can overwrite it. Commit the baseline
after the first sync.

With a baseline, a file is only overwritten when it still matches the previous
baseline. If it does not, both sides changed and you get a conflict instead.

### A conflict never goes away

That is intentional. The baseline stays at the last agreed state until the
local file matches upstream. Resolve the file, remove `<file>.upstream`, and
run a command that syncs again.

### A file deleted upstream is still present

You modified it, so it was preserved with a warning. Delete it yourself, or
restore it to the baseline before the next sync.

### A mirror does not affect the build

A mirror syncs files but does not make the working copy part of the dependency
graph. Include the mirror's `composer.json` with
`wikimedia/composer-merge-plugin`, or reference the code another way. Do not add
a path repository pointing at the mirror unless you intend to replace the
installed package.

### `Invalid or unsupported source plugin lock`

`composer-source.lock` is malformed or has an unsupported `version`. Restore it
from version control. If it is not committed and the data is expendable, move
it aside and run `composer update` to rebuild state from the available
baselines.

## Aliases and rebases

### No aliases were generated

1. Is the plugin active?
2. Did `post-autoload-dump` run? `--no-scripts` and `--no-plugins` suppress it.
3. Does the package actually contain a symbol under the configured source
   prefix?
4. Is the package name in a package-scoped map exact and lowercase?
5. Does `vendor/composer/namespace_aliases.php` exist? Run
   `composer dump-autoload -vvv` and look for `Generated ... namespace aliases.`

Rebase aliases additionally need a matching PSR-4 prefix. If no PSR-4 mapping
exists, the rebase is skipped silently.

### A class is not aliased

Simple aliases cover classes, interfaces, traits, and enums discovered by the
tokenizer. They do not alias functions or constants, and they skip top-level
`tests/`, `test/`, `vendor/`, `build/`, `dist/`, and any `phpstan/` directory.

A symbol that is not autoloadable under its source name cannot be aliased into
existence.

### The rebased tree is missing a file

- For `copy: "package"`, the whole package is copied except the default
  excluded directories and your `exclude` list.
- For `copy: "autoload"`, only the PSR-4 directory for the source prefix,
  `autoload.files`, and `include` paths are copied.
- `exclude` removes complete subtrees at any depth.
- `include` has no effect with `copy: "package"`.

### The rebase left old namespaces in some files

The transform is a textual replacement of the namespace string, not an AST
rewrite. Files that construct the namespace dynamically or split it across
tokens may not be rewritten. Review generated output and choose distinct,
unambiguous prefixes.

### Aliases work in one environment but not another

Generated files under `vendor/composer/` are not committed. Every environment
must run a command that dumps the autoloader with the plugin active. Check
`allow-plugins` in CI as well as locally.

### The Illuminate provider does not register aliases

- The application must use Illuminate; the provider is not loaded otherwise.
- The package must be discovered through Laravel's package discovery
  (`extra.laravel.providers`).
- `vendor/composer/source_aliases.php` must exist and be non-empty.
- Clear cached package metadata if discovery ran before the file existed.

## Lock files

### Which files should be committed?

| File | Commit? |
| --- | --- |
| `composer.lock` | Yes, normally. |
| `composer-source.lock` | Yes when using `mirror-state: lock` or projections. |
| `<mirror>/.source-plugin/sync.json` | Yes in `mirror-state: local`. |
| `vendor/**` generated files | No. |
| `vendor/<pkg>/.source-plugin/projection.json` | No; it lives in `vendor/`. |

### `composer install` changed nothing but `composer update` did

That is the intended split. Install is read-only for projection and lock-mode
mirror state; update may advance both. See [lifecycle](./lifecycle.md).

### State is corrupted after switching modes

Switching `local` → `lock` migrates a recognised baseline and removes the legacy
file only after a successful save. Switching `lock` → `local` does not export
state. If you switch back, the plugin starts from whatever local baselines
exist, which may be none. Commit the mode you intend to use.

## When to rebuild

If state is inconsistent and the working tree has no local work to preserve:

```sh
rm -rf vendor
composer update
```

This rebuilds packages, projections, mirrors, and aliases from configuration.
Use it as a last resort; it is not needed for ordinary configuration changes,
where `composer update` or `composer dump-autoload` is enough.
