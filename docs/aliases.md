# Aliases and rebases

Aliases keep old class names working after a namespace change, or expose a
package under a second namespace. They are generated during Composer's
`post-autoload-dump` event and do not change which package is installed.

There are two mechanisms:

| Type | What it does | Cost |
| --- | --- | --- |
| `simple` | Creates runtime `class_alias` entries for discovered classes, interfaces, traits, and enums. | No file copies, no source changes. |
| `rebase` | Copies or rewrites PHP source so the target namespace is the real implementation. | Copies files; textual namespace rewrite. |

Use `simple` when old and new names should point at the same implementation.
Use `rebase` when the target namespace must own the code, for example when both
must be autoloadable at the same time.

## Configuration forms

### Flat map (legacy)

```json
{
    "aliases": {
        "Acme\\Library\\": "Legacy\\Library\\"
    }
}
```

A flat map applies its mappings to every installed package. It is shorthand for
a simple alias and cannot use rebase options.

### Package-scoped map

```json
{
    "aliases": {
        "acme/library": {
            "Acme\\Library\\": "Legacy\\Library\\"
        },
        "acme/fluent": {
            "Acme\\Fluent\\": "Local\\Fluent\\",
            "type": "rebase"
        }
    }
}
```

The package key scopes the mapping to that exact installed package. The
reserved keys are `type`, `copy`, `include`, `exclude`, and `destination`;
every other key is a source → target namespace prefix.

At least one namespace mapping is required. Empty prefixes and duplicate source
prefixes are rejected. A source prefix may only be configured once across the
whole alias map, even in different package scopes.

## Simple aliases

```json
{
    "aliases": {
        "acme/library": {
            "Acme\\Library\\": "Legacy\\Library\\"
        }
    }
}
```

For each installed package the mapping applies to, the plugin scans the
installed source for namespace declarations and class-like symbols. A symbol
under the source prefix is aliased to the same suffix under the target prefix:

```text
Acme\Library\Client            ->  Legacy\Library\Client
Acme\Library\Contracts\Store   ->  Legacy\Library\Contracts\Store
```

Generated output goes to `vendor/composer/namespace_aliases.php` and looks
conceptually like:

```php
if (class_exists('Acme\Library\Client') && ! class_exists('Legacy\Library\Client')) {
    class_alias('Acme\Library\Client', 'Legacy\Library\Client');
}
```

The correct existence checker is used per symbol kind: `class_exists`,
`interface_exists`, `trait_exists`, or `enum_exists`. Running the file is
idempotent.

Scanning rules:

- classes, interfaces, traits, and enums are discovered with PHP's tokenizer;
- anonymous classes are skipped;
- top-level `tests/`, `test/`, `vendor/`, `build/`, and `dist/` directories and
  any `phpstan/` directory are excluded;
- function and constant aliases are not generated;
- the implementation still has to be autoloadable under its source name.

Simple aliases are metadata only. They do not change the installed package or
its autoload rules.

## Rebase aliases

A rebase creates code that actually uses the target namespace. It requires a
package-scoped alias:

```json
{
    "aliases": {
        "acme/library": {
            "Acme\\Library\\": "Local\\Library\\",
            "type": "rebase"
        }
    }
}
```

The source prefix must match a PSR-4 prefix declared by the package:

```json
{
    "autoload": {
        "psr-4": {
            "Acme\\Library\\": "src/"
        }
    }
}
```

If no matching PSR-4 mapping exists, no rebase mapping is emitted. The plugin
does not guess a directory.

By default (`destination: "generated"`), the rebased tree is written to:

```text
vendor/composer/rebased/<vendor>--<package>/<original-path>
```

For `acme/library` with `"Acme\\Library\\": "src/"`:

```text
vendor/composer/rebased/acme--library/src/Client.php
```

The package's original `vendor/acme/library` tree is left unchanged.

## Copy policy

| `copy` | What is copied |
| --- | --- |
| `package` (default) | The whole package, preserving package-relative paths. Top-level `tests/`, `test/`, `vendor/`, `build/`, `dist/`, and any `phpstan/` directory are skipped. |
| `autoload` | The PSR-4 directory for the configured source prefix, declared `autoload.files`, and explicit `include` paths. |

`include` adds package-relative paths when `copy: "autoload"` is used. It has
no effect on a whole-package copy.

`exclude` removes complete subtrees at any depth for either copy policy. Paths
are package-relative and do not support globs:

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

With `copy: "autoload"`, the generated tree is cleared once per autoload dump
before it is repopulated, so switching policies cannot leave yesterday's
deleted classes behind.

`copy`, `include`, and `exclude` are rejected on a simple alias.

## What a rebase changes

`NamespaceRebaser` performs a textual replacement:

```text
Acme\Fluent        ->  Local\Fluent
Acme\\Fluent       ->  Local\\Fluent   (escaped string form)
```

It is deliberately not an AST rewrite. It does not understand `use`
statements, comments, or strings separately; it replaces the namespace token
wherever it appears. Choose distinct prefixes and review generated output when
the namespace also appears in unrelated text.

Non-PHP files are copied byte-for-byte.

## Generated rebase metadata

The plugin writes `vendor/composer/namespace_rebases.php`. It contains:

- a map of target prefix → rebased PSR-4 directories;
- a prepended autoloader that loads target-namespace classes from those
  directories;
- lazy aliases so the original source name resolves to the target
  implementation;
- `require_once` calls for rebased copies of `autoload.files` entries.

Only `autoload.files` whose content mentions the relevant namespace are
required. Requiring an unchanged global helper would redeclare symbols.

The result is that both names are usable:

```php
new Acme\Library\Client();   // resolves through the generated alias
new Local\Library\Client();  // loads the rebased implementation
```

## Rebasing the mirror itself

Set `destination: "mirror"` to make a mirror working copy use the target
namespace:

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

During mirror sync, PHP source is transformed before it is written, and the
transformed content becomes the baseline. Local edits stay protected and
conflicts keep the file-level rules. See [mirrors](./mirrors.md).

The mirror's `composer.json` is not namespace-rewritten, because Composer has no
namespace in that field. Generated rebase metadata still maps the target
namespace to the mirror-derived source so the target namespace autoloads.

With `mirror-state: "lock"`, an existing namespace mapping cannot be changed
against a locked baseline without an explicit working-copy migration.

## How the files are loaded

`src/bootstrap.php` is registered as a Composer `files` autoload entry of the
plugin package itself. Composer only emits the files-loading section when at
least one installed package declares such an entry, so declaring it guarantees
the generated files can be loaded:

```text
vendor/composer/namespace_aliases.php
vendor/composer/namespace_rebases.php
```

The bootstrap locates `vendor/` through `Composer\InstalledVersions`, with a
filesystem fallback, and `require_once`s both files when present. It does not
patch Composer's generated autoloader.

`vendor/composer/source_aliases.php` is different: it returns a PHP array of
class/interface aliases and is read by the optional container integrations.

## Illuminate container aliases

When the application uses Illuminate, the plugin's service provider registers
the same compatibility names:

```php
$this->app->alias($source, $alias);
```

It reads `vendor/composer/source_aliases.php` relative to the application base
path. The bridge is optional and is not loaded in non-Illuminate applications.
The Composer plugin remains framework-neutral; only generated metadata is shared
with the provider.

## Symfony container aliases

Symfony keys services by string as well, so the same compatibility names help
when the original class is a registered service and a consumer type-hints the
alias. `class_alias()` already covers ordinary `new` calls and autowired
arguments, so most applications need nothing at all.

Register the compiler pass when the source class is a service:

```php
// src/Kernel.php

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webong\ComposerSource\Symfony\ComposerSourceAliasesPass;

protected function build(ContainerBuilder $container): void
{
    $container->addCompilerPass(new ComposerSourceAliasesPass());
}
```

The pass reads `vendor/composer/source_aliases.php` and registers
`Legacy\Library\Client -> Acme\Library\Client` for every mapped class or
interface whose source is a service. It:

- skips mappings whose source is not registered, so container compilation does
  not fail on a dangling reference;
- leaves existing definitions and aliases untouched;
- keeps the source service's public/private visibility;
- is a no-op when the map is missing, so `--no-scripts` installs still boot.

`symfony/dependency-injection` is only suggested; the pass is never loaded
unless the application references it. Symfony has no equivalent of Laravel's
provider auto-discovery, so the pass is registered manually. A bundle can
register it in its own `build()` method if you ship one.

Because the container is compiled and cached, clear the cache after changing
`aliases`:

```sh
composer dump-autoload
php bin/console cache:clear
```

For a one-off mapping, `services.yaml` is enough without the pass:

```yaml
services:
    Legacy\Library\Client: '@Acme\Library\Client'
```

## Commands and regeneration

Aliases are generated on every `post-autoload-dump`:

| Command | Aliases regenerated? |
| --- | --- |
| `composer install` | yes, when the autoloader is dumped. |
| `composer update` | yes. |
| `composer dump-autoload` | yes. |
| `composer reinstall <pkg>` | yes, when the autoloader is dumped. |
| `--no-plugins` | no; existing generated files may remain. |
| first run that installs the plugin | no; the plugin is not active yet. |

After changing `aliases`, run `composer dump-autoload` (or any command that
dumps the autoloader). If an alias still does not appear, make sure the plugin
is allowed and the generated files are being loaded.

## Limits

- **Source must be autoloadable.** Simple aliases only alias symbols the plugin
  can discover; a class that is never autoloadable is not fixed by aliasing.
- **Textual rebase.** The namespace replacement is not syntax-aware.
- **PSR-4 required for rebase.** A prefix without a matching PSR-4 directory is
  skipped.
- **Generated files are disposable.** Do not edit them or commit them under
  `vendor/`; regenerate instead.
- **One mapping per source prefix.** Define a broader prefix once rather than
  repeating overlapping mappings.
