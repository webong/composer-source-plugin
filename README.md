# Composer Namespace Alias

`webong/composer-namespace-alias` is a Composer plugin that generates PHP namespace compatibility aliases during `composer dump-autoload`.

## Configuration

Add the plugin and declare an alias on the package whose classes should be exposed under another namespace:

```json
{
    "extra": {
        "namespace-alias": {
            "Webong\\WebhookProxy\\": "Zorvia\\WebhookProxy\\"
        }
    }
}
```

The plugin discovers classes, interfaces, traits, and enums in that package and generates aliases in Composer's autoload files. The original namespace remains valid, so this is suitable for gradual migrations.

Because Composer plugins execute code while installing dependencies, consumers must explicitly allow the plugin:

```json
{
    "config": {
        "allow-plugins": {
            "webong/composer-namespace-alias": true
        }
    }
}
```

## Development

```bash
composer install
composer test
```

The plugin requires Composer 2 and PHP 8.1 or newer.

## Selecting a local or external package source

When a package has both a local path checkout and an external Composer
package, configure the preference in the consuming application's root
`composer.json`:

```json
{
    "extra": {
        "composer-namespace-alias": {
            "sources": {
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

Supported values are `local`, `external`, and `auto`. `auto` chooses a path
repository when one is available and otherwise uses the external package.

Declare the local checkout as a path repository with the same package name;
do not merge its manifest into the root package alongside the external
requirement:

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
