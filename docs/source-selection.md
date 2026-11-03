# Source selection

Source selection solves a specific problem: a package is available twice — once
as a manifest merged into the root package, and once as a normal package that
Composer can install. Both may declare the same namespace, so only one should
survive the build.

`loaders` decides which one wins. The decision happens at Composer's
`pre-pool-create` event, before the dependency solver runs.

## Configuration

```json
{
    "extra": {
        "source-plugin": {
            "loaders": {
                "webong/web-proxy": {
                    "type": "inline",
                    "path": "ext/web-proxy",
                    "manifest": "ext/web-proxy/composer.json"
                }
            }
        }
    }
}
```

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `type` | no | `auto` | `inline`, `outline`, or `auto`. |
| `path` | no | — | Local package directory. Used to recognise a path candidate and to strip merged autoload entries when `outline` wins. |
| `manifest` | no | — | Local manifest. Used by `auto` to decide whether the inline side exists, and by `outline` to strip merged requirements. |

`path` and `manifest` are only needed when the corresponding cleanup or
detection applies. A workspace package already supplies a path repository, so
it does not need a loader entry to be linked.

## What the three types mean

| Type | Preference | If the preferred candidate is absent |
| --- | --- | --- |
| `inline` | A candidate whose dist type is `path`. | When candidates exist, removes every candidate and removes the root requirement, which is the merged-manifest case. If there are no candidates at all, nothing is removed. |
| `outline` | A candidate whose dist type is not `path`. | Nothing is removed. |
| `auto` | A `path` candidate, otherwise a non-path candidate. | If `manifest` exists and there is no path candidate, behaves like `inline`; otherwise nothing is removed. |

"Preferred" means the first candidate of that kind in the order Composer
provided them. Other candidates for the same package are removed from the
pool.

When `outline` wins, the plugin also:

- removes root autoload `psr-4` and `psr-0` entries whose paths resolve inside
  the configured `path`;
- removes the local manifest's `require` entries from the root package, except
  `php` and `composer-plugin-api`.

That second step prevents dependencies that were merged only for the inline
implementation from remaining in the root package.

## Example: merged manifest wins

With `wikimedia/composer-merge-plugin`, the local manifest is merged into the
root package before the plugin runs:

```json
{
    "require": {
        "webong/composer-source-plugin": "^1.0",
        "wikimedia/composer-merge-plugin": "^2.1"
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

If no package candidate exists, the merged manifest is the implementation, and
the loader removes the now-unnecessary root requirement. If a `path` candidate
does exist, `inline` keeps it and drops the published candidates instead.

## Example: published package wins

```json
{
    "extra": {
        "source-plugin": {
            "loaders": {
                "webong/web-proxy": {
                    "type": "outline",
                    "path": "ext/web-proxy",
                    "manifest": "ext/web-proxy/composer.json"
                }
            }
        }
    }
}
```

The published package is installed. The local directory's autoload entries and
requirements are removed from the root package so the two copies do not both
register `Webong\WebProxy\`.

## Example: decide from the filesystem

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

`auto` is convenient for a checkout where the local directory may or may not
exist. It never invents a package: if there is no candidate and no merged
manifest, nothing happens.

## Loaders versus workspaces

A workspace is not a loader entry, but it behaves like an implicit `inline`
loader for conflict validation and repository injection. This means:

- declaring a workspace links the local directory automatically;
- an explicit loader of type `outline` disables the link for that package;
- declaring `loaders` for a package that is also mirrored or projected is
  rejected, whether the loader is `inline`, `outline`, or `auto`.

See [workspaces](./workspaces.md) and [configuration](./configuration.md).

## Loaders versus merge-plugin

The plugin does not require `wikimedia/composer-merge-plugin`, and it does not
merge manifests itself. It only arbitrates once the merge plugin (or a path
repository) has made both candidates visible.

Two consequences follow:

- `loaders` without merge-plugin or a path repository has nothing to choose
  between; a `path` repository is the simplest way to make an inline candidate.
- `outline` cleanup only knows about autoload entries and requirements visible
  in the merged root manifest. It does not rewrite files on disk.

## Lifecycle and commands

`pre-pool-create` is only dispatched when Composer builds a dependency pool.
That includes `install`, `update`, `require`, and `remove` with an install
phase. It does not run for a pure `dump-autoload`, and it does not run when
plugins are disabled.

| Command | Loader runs? | Effect |
| --- | --- | --- |
| `composer update` | yes | Chooses the winner, then resolves and installs. |
| `composer install` with an existing lock | yes at resolution time | Uses the locked source; the selection is not re-recorded. |
| `composer install --no-plugins` | no | Composer installs what the lock says, without any plugin cleanup. |
| `composer dump-autoload` | no | Autoload is rebuilt from already-installed packages. |
| `composer update --no-install` | yes | Selects in memory; nothing is installed or recorded. |

The plugin cannot run on the `composer require` that installs it for the first
time. See the [first-run rule](./README.md#the-first-run-rule).

## Failure modes

- **Wrong winner after a config change**: loaders are not recorded in
  `composer-source.lock`. Update `composer.lock` with a full `composer update`
  after changing `loaders`.
- **Duplicate namespace errors**: the inline side was not actually removed.
  Check that `path` resolves to the local directory and that `manifest` points
  at the merged file.
- **Package disappears from `composer show`**: an `inline` loader with no path
  candidate removed it from the pool because the merged manifest is supposed to
  provide the code. If that is not what you intended, set `type` to `outline`.
- **`--no-plugins` produces a different tree**: expected. The plugin is the
  mechanism that removes the losing candidate; without it Composer sees both.
