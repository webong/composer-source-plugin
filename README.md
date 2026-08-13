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
