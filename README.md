# Composer Source Plugin

`webong/composer-source-plugin` selects the winning source when a Composer
package is available both as an inline merged manifest and as an outline
package. It also retains the optional namespace-compatibility alias generator.

## Source selection

Configure the winner in the consuming application's root `composer.json`:

```json
{
    "extra": {
        "composer-source": {
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
        "composer-source": {
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

## Unified configuration

Package source selection and namespace aliases are configured together under
`extra.composer-source`:

```json
{
    "extra": {
        "composer-source": {
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
