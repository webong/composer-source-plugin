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

Merging is file-level, not line-level. The plugin records what the mirror
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
```

Requires Composer 2 and PHP 8.1 or newer.
