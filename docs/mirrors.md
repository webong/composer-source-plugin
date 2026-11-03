# Mirrors

A mirror keeps an editable working copy of an installed dependency at a path in
your repository, while the published package stays the installed one. On each
Composer run that dumps the autoloader, the plugin compares upstream, your
working copy, and the last synced baseline, then copies changes in one
direction: upstream → working copy.

Use a mirror when you want to **edit a dependency and still receive upstream
releases**. If you only want to replace a package with a local directory, use a
[workspace](./workspaces.md) instead.

## Configuration

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
            }
        }
    }
}
```

The shorthand form is equivalent:

```json
{
    "mirrors": {
        "acme/fluent": "ext/fluent"
    }
}
```

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `path` | yes | — | Working-copy directory. Relative paths resolve from the directory Composer runs in, normally the project root. Absolute paths are allowed. |
| `origin` | no | `remote` | Only `remote` is supported. The source is the installed package. |

The package must still be required normally. A mirror does not add a
requirement and does not install anything.

Do **not** add a `path` repository pointing at the mirror. That would make
Composer treat your working copy as the package, which stops upstream versions
from ever being downloaded, and it removes the thing a mirror is meant to sync.

The mirror directory is created if it does not exist. Composer's `vendor/`
copy, its download cache, and any upstream checkout are never modified.

## What gets mirrored

Everything in the installed package except these path segments:

```text
vendor/  .git/  .github/  .source-plugin/
```

Exclusion applies at any depth. The mirror baseline lives in
`.source-plugin/sync.json` (or in `composer-source.lock`) and is therefore not
itself mirrored.

`composer.json` is mirrored like any other file, so local edits to it are
protected by the same rules. If it is included by
`wikimedia/composer-merge-plugin`, the working copy contributes its metadata to
the build. The mirror never rewrites the package name.

## Sync rules

The merge is file-level, not line-level. For every file upstream ships:

| Baseline | Local | Upstream | Result |
| --- | --- | --- | --- |
| any | matches upstream | any | Untouched; baseline refreshed. |
| no entry | missing | exists | Written from upstream. |
| no entry | exists | exists | Local content preserved; upstream is recorded as the new baseline. This is an "unbased" file. |
| exists | unchanged since baseline | changed | Upstream version written. |
| exists | changed | same as baseline | Local edit kept. |
| exists | changed | changed | **Conflict**: local file kept, upstream saved beside it as `<file>.upstream`. Baseline is deliberately not advanced. |

Consequences:

- A file you edited is never overwritten because upstream also changed it.
- A conflict survives across syncs until your file matches upstream (or you
  replace it from the sidecar). This is what makes the conflict impossible to
  forget.
- A file that exists only locally is never touched and never enters the
  baseline.
- A file deleted upstream is removed locally only when it still matches the
  baseline. If you modified it, it is kept with a warning.

The `.upstream` sidecar is written next to the original path, for example
`src/Client.php.upstream`. It is a normal file, not a merge marker.

## The baseline

### Local mode (default)

```json
{
    "mirror-state": "local"
}
```

Each mirror keeps its own baseline at:

```text
ext/fluent/.source-plugin/sync.json
```

The file records the package name, the upstream reference, and a SHA-256 hash
for every synced file. Commit it with the working copy so machines agree on
what "unchanged" means.

A working copy without a baseline is treated as **unbased**: existing files are
kept, the current upstream content becomes the baseline, and a warning is
printed. This is safe but means the first sync cannot distinguish your edits
from upstream's. Commit the baseline immediately after the first sync.

### Project lock mode

```json
{
    "mirror-state": "lock"
}
```

Baselines move into `composer-source.lock` beside `composer.json`, alongside
projection state. The file is atomic, machine-independent, and survives
Composer's own lock rewrites. Commit it together with `composer.lock` and the
mirrored sources.

| Lock field | Meaning |
| --- | --- |
| `version` | Source lock format version (`1`). |
| `mirrors.<package>.reference` | Upstream source/dist reference last synced. |
| `mirrors.<package>.files` | Relative path → SHA-256 hash. |
| `mirrors.<package>.namespaces` | Source → target namespace map for a mirror rebase. |

In lock mode:

- `composer update` may advance the lock.
- `composer install` and `composer dump-autoload` verify the baseline and do
  not rewrite it.
- If the baseline is missing or the installed reference differs, the sync is
  skipped with a warning telling you to run `composer update`.
- If a mirror rebase changes its namespace mapping against an existing locked
  baseline, the sync is rejected until the working copy is migrated.
- Resolution-only updates (`--no-install`), dry runs, and runs with plugins
  disabled cannot erase the file.

Migrating from `local` to `lock` is automatic on the next `composer update`: a
recognised `.source-plugin/sync.json` is read, the lock is written, and only
then is the legacy file removed. Other files in `.source-plugin/` are retained.
Switching back to `local` does **not** export the lock state.

## Lifecycle

The sync runs on `pre-autoload-dump`, immediately before Composer generates the
autoloader. That is after installation and after any mirror-rebase transform is
known, so generated aliases always see post-sync sources.

A failing sync prints a warning and never aborts the install. The rest of the
Composer operation continues.

| Command | Sync behaviour |
| --- | --- |
| `composer update` | Syncs; may advance the baseline. |
| `composer install` | Syncs against the committed baseline; may skip with a warning on a reference mismatch. |
| `composer dump-autoload` | Same as `install`: verifies and applies the committed baseline. |
| `composer reinstall <pkg>` | Reinstalls the package and syncs. In lock mode the baseline is verified, not advanced. |
| `composer update --no-install` | No package install, no autoload dump, no sync. |
| `composer update --dry-run` | Composer dispatches no package or script events; no sync. |
| `--no-plugins` | No plugin, no sync. |
| first run that installs the plugin | The plugin is not active yet; no sync. |

## Conflicts

A conflict means both sides changed the same file since the last sync. The
plugin leaves your copy in place and writes the upstream version next to it:

```text
ext/fluent/src/Client.php           # your version, untouched
ext/fluent/src/Client.php.upstream  # upstream version
```

Resolve by editing the local file, replacing it with the sidecar, or merging
manually. Delete the sidecar when done. The next sync treats the file as
resolved once it matches upstream; until then it is reported again.

There is no automatic line-level merge, and there is no "ours" or "theirs"
flag. This is deliberate: silently merging generated or machine-specific files
is more dangerous than reporting them.

## Mirror rebase

A `rebase` alias can rewrite the mirror itself instead of generating a separate
copy:

```json
{
    "mirrors": {
        "webong/cogent": { "path": "ext/cogent" }
    },
    "aliases": {
        "webong/cogent": {
            "Webong\\Cogent\\": "Zorvia\\Cogent\\",
            "type": "rebase",
            "destination": "mirror"
        }
    }
}
```

With `destination: "mirror"`:

- the source mirror still syncs from the installed `webong/cogent` package;
- PHP source is transformed into the target namespace before it is written;
- the baseline records the transformed content, so the transform is not
  mistaken for a local edit on the next sync;
- local edits remain protected, and conflicts keep the same file-level rules;
- the Composer package name is unchanged.

The transform is a textual namespace replacement, not an AST rewrite. Choose
distinct prefixes and avoid relying on the same token appearing in unrelated
strings.

The mirror rebase applies to the whole package. `copy`, `include`, and `exclude`
limit generated rebases (`destination: "generated"`), not mirror sync. See
[aliases](./aliases.md).

## Interaction with other features

- A package cannot be both mirrored and a loader, a workspace, or a projection.
  The plugin rejects the combination at activation.
- Aliases are allowed. A `destination: "generated"` rebase reads from the
  mirror working copy, so it sees your local edits; a `destination: "mirror"`
  rebase rewrites the working copy during sync.
- Projections and mirrors share `composer-source.lock` but cannot target the
  same package.

## Limits

- **File-level, not line-level.** Both-sides edits are reported, never merged.
- **No upstream deletion of edited files.** A locally modified file that
  upstream deletes is kept with a warning.
- **Baseline discipline.** Without a committed baseline, the first sync cannot
  tell local edits apart from upstream content. Commit the baseline.
- **No reverse sync.** Edits flow upstream → working copy only. Publishing your
  working copy is your responsibility.
- **Mirror sync is best-effort.** Errors warn and continue, so a broken mirror
  will not block an otherwise successful install. Check warnings in CI.
