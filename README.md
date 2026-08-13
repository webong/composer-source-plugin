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
                "webong/web-proxy": {
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
        "webong/web-proxy": "@dev"
    },
    "extra": {
        "merge-plugin": {
            "include": ["ext/web-proxy/composer.json"]
        },
        "composer-source": {
            "packages": {
                "webong/web-proxy": {
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
            "packages": {
                "zorvia/web-proxy": {
                    "preference": "local",
                    "local_manifest": "ext/web-proxy/composer.json"
                }
            },
            "aliases": {
                "Webong\\WebhookProxy\\": "App\\WebhookProxy\\"
            }
        }
    }
}
```

`packages` controls which implementation wins. `aliases` is a map from an
existing namespace prefix to the compatibility namespace prefix. The plugin
discovers production classes, interfaces, traits, and enums in installed
packages and writes the aliases during Composer's autoload generation. It also
writes the production class/interface aliases to
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
