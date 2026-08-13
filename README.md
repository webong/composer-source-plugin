# Composer Source Plugin

`webong/composer-source-plugin` selects the winning source when a Composer
package is available both as a local merged manifest and as an external
package. It also retains the optional namespace-compatibility alias generator.

## Source selection

Configure the winner in the consuming application's root `composer.json`:

```json
{
    "extra": {
        "composer-source": {
            "packages": {
                "zorvia/web-proxy": {
                    "preference": "auto",
                    "local_path": "ext/web-proxy",
                    "local_manifest": "ext/web-proxy/composer.json"
                }
            }
        }
    }
}
```

The preference can be:

- `local`: the local manifest/path source wins.
- `external`: the normal Composer package wins.
- `auto`: local wins when the configured manifest exists; otherwise external wins.

The plugin removes the losing package candidate before Composer resolves the
dependency pool. When the external source wins, it also removes the local
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
        "zorvia/web-proxy": "@dev"
    },
    "extra": {
        "merge-plugin": {
            "include": ["ext/web-proxy/composer.json"]
        },
        "composer-source": {
            "packages": {
                "zorvia/web-proxy": {
                    "preference": "local",
                    "local_manifest": "ext/web-proxy/composer.json"
                }
            }
        }
    }
}
```

The local and external definitions may both be declared, but only the
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
        "zorvia/web-proxy": "@dev"
    }
}
```

## Unified configuration

Package source selection and namespace aliases are configured together under
`extra.composer-source`:

```json
{
    "extra": {
        "composer-source": {
            "packages": {
                "zorvia/web-proxy": {
                    "preference": "local",
                    "local_manifest": "ext/web-proxy/composer.json"
                }
            },
            "aliases": {
                "Webong\\WebhookProxy\\": "Zorvia\\WebhookProxy\\"
            }
        }
    }
}
```

`packages` controls which implementation wins. `aliases` is a map from an
existing namespace prefix to the compatibility namespace prefix. The plugin
discovers classes, interfaces, traits, and enums in installed packages and
writes the aliases during Composer's autoload generation.

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
