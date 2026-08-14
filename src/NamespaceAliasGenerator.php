<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class NamespaceAliasGenerator
{
    private const EXTRA_KEY = 'composer-source';
    private const ALIASES_KEY = 'aliases';
    private const AUTOLOAD_FILE = 'namespace_aliases.php';
    private const REBASE_AUTOLOAD_FILE = 'namespace_rebases.php';
    private const CONTAINER_ALIASES_FILE = 'source_aliases.php';
    private const AUTOLOAD_FILES_MARKER = 'webong/composer-source-plugin';
    private const REBASE_AUTOLOAD_FILES_MARKER = 'webong/composer-source-plugin-rebases';

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {
    }

    public function generate(): void
    {
        $vendorDirectory = $this->composer->getConfig()->get('vendor-dir');
        $autoloadFiles = $vendorDirectory . '/composer/autoload_files.php';
        $generatedFile = $vendorDirectory . '/composer/' . self::AUTOLOAD_FILE;
        $rebaseGeneratedFile = $vendorDirectory . '/composer/' . self::REBASE_AUTOLOAD_FILE;
        $containerAliasesFile = $vendorDirectory . '/composer/' . self::CONTAINER_ALIASES_FILE;
        $aliases = [];
        $rebases = [];

        $configured = $this->configuredAliases();

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $aliases = array_merge($aliases, $this->aliasesForPackage($package, $configured));
            $rebases = array_merge($rebases, $this->rebasesForPackage($package, $configured, $vendorDirectory));
        }

        $this->writeAliasFile($generatedFile, $aliases);
        $this->writeRebaseAutoloadFile($rebaseGeneratedFile, $rebases);
        $this->writeContainerAliasesFile($containerAliasesFile, $aliases);
        $this->registerAutoloadFile($autoloadFiles, self::AUTOLOAD_FILES_MARKER, self::AUTOLOAD_FILE, $aliases !== []);
        $this->registerAutoloadFile($autoloadFiles, self::REBASE_AUTOLOAD_FILES_MARKER, self::REBASE_AUTOLOAD_FILE, $rebases !== []);

        if ($aliases !== []) {
            $this->io->writeError(sprintf('<info>Generated %d namespace aliases.</info>', count($aliases)));
        }

        if ($rebases !== []) {
            $this->io->writeError(sprintf('<info>Generated %d namespace rebases.</info>', count($rebases)));
        }
    }

    /** @return list<array{source: string, alias: string, kind: string}> */
    /** @param list<NamespaceAliasDefinition> $configured */
    private function aliasesForPackage(PackageInterface $package, array $configured): array
    {
        if (! is_array($configured) || $configured === []) {
            return [];
        }

        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
        if (! is_string($installPath) || ! is_dir($installPath)) {
            return [];
        }

        $aliases = [];
        foreach ($configured as $definition) {
            if ($definition->type !== NamespaceAliasDefinition::TYPE_SIMPLE || ! $definition->appliesTo($package->getName())) {
                continue;
            }

            $sourcePrefix = $definition->sourcePrefix;
            $aliasPrefix = $definition->targetPrefix;

            foreach ($this->discoverSymbols($installPath) as $symbol) {
                if (! str_starts_with($symbol['name'], $sourcePrefix)) {
                    continue;
                }

                $suffix = substr($symbol['name'], strlen($sourcePrefix));
                $aliases[] = [
                    'source' => $symbol['name'],
                    'alias' => $aliasPrefix . $suffix,
                    'kind' => $symbol['kind'],
                ];
            }
        }

        return $aliases;
    }

    /** @return list<NamespaceAliasDefinition> */
    private function configuredAliases(): array
    {
        $extra = $this->composer->getPackage()->getExtra()[self::EXTRA_KEY] ?? [];
        $aliases = is_array($extra) ? ($extra[self::ALIASES_KEY] ?? []) : [];

        return is_array($aliases) ? NamespaceAliasConfiguration::parse($aliases) : [];
    }

    /**
     * @param list<NamespaceAliasDefinition> $configured
     * @return list<array{source: string, target: string, target_prefix: string, kind: string, paths: list<string>}>
     */
    private function rebasesForPackage(PackageInterface $package, array $configured, string $vendorDirectory): array
    {
        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
        if (! is_string($installPath) || ! is_dir($installPath)) {
            return [];
        }

        $rebases = [];
        foreach ($configured as $definition) {
            if ($definition->type !== NamespaceAliasDefinition::TYPE_REBASE || ! $definition->appliesTo($package->getName())) {
                continue;
            }

            $paths = $this->rebasePackage($package, $installPath, $vendorDirectory, $definition);
            if ($paths === []) {
                continue;
            }

            foreach ($this->discoverSymbols($installPath) as $symbol) {
                if (! str_starts_with($symbol['name'], $definition->sourcePrefix)) {
                    continue;
                }

                $rebases[] = [
                    'source' => $symbol['name'],
                    'target' => $definition->targetPrefix . substr($symbol['name'], strlen($definition->sourcePrefix)),
                    'target_prefix' => $definition->targetPrefix,
                    'kind' => $symbol['kind'],
                    'paths' => $paths,
                ];
            }
        }

        return $rebases;
    }

    /** @return list<string> */
    private function rebasePackage(PackageInterface $package, string $installPath, string $vendorDirectory, NamespaceAliasDefinition $definition): array
    {
        $autoload = $package->getAutoload()['psr-4'] ?? [];
        $sourceDirectories = $autoload[$definition->sourcePrefix] ?? [];
        if (! is_array($sourceDirectories)) {
            $sourceDirectories = [$sourceDirectories];
        }

        $paths = [];
        foreach ($sourceDirectories as $sourceDirectory) {
            if (! is_string($sourceDirectory)) {
                continue;
            }

            $sourcePath = $installPath . '/' . trim($sourceDirectory, '/');
            if (! is_dir($sourcePath)) {
                continue;
            }

            $rebasedPath = $vendorDirectory . '/composer/rebased/' . str_replace('/', '--', $package->getName()) . '/' . trim($sourceDirectory, '/');
            $this->rebaseDirectory($sourcePath, $rebasedPath, $definition);
            $paths[] = $rebasedPath;
        }

        return $paths;
    }

    private function rebaseDirectory(string $sourcePath, string $rebasedPath, NamespaceAliasDefinition $definition): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcePath));
        $rebaser = new NamespaceRebaser;

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = ltrim(str_replace($sourcePath, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $target = $rebasedPath . DIRECTORY_SEPARATOR . $relativePath;
            $targetDirectory = dirname($target);
            if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0777, true) && ! is_dir($targetDirectory)) {
                throw new RuntimeException('Unable to create rebased source directory: ' . $targetDirectory);
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                throw new RuntimeException('Unable to read PHP file: ' . $file->getPathname());
            }

            file_put_contents($target, $rebaser->rebase($contents, $definition));
        }
    }

    /** @return list<array{name: string, kind: string}> */
    private function discoverSymbols(string $directory): array
    {
        $symbols = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if ($this->isExcludedPath($file->getPathname(), $directory)) {
                continue;
            }
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $symbols = array_merge($symbols, $this->symbolsInFile($file->getPathname()));
        }

        return $symbols;
    }

    private function isExcludedPath(string $file, string $directory): bool
    {
        $relative = ltrim(str_replace($directory, '', $file), DIRECTORY_SEPARATOR);

        return preg_match('/^(?:tests?|vendor|build|dist)(?:'.preg_quote(DIRECTORY_SEPARATOR, '/').'|$)/i', $relative) === 1;
    }

    /** @return list<array{name: string, kind: string}> */
    private function symbolsInFile(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Unable to read PHP file: ' . $file);
        }

        $tokens = token_get_all($contents);
        $namespace = '';
        $symbols = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! is_array($tokens[$index])) {
                continue;
            }

            if ($tokens[$index][0] === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $index);
                continue;
            }

            $kind = match ($tokens[$index][0]) {
                T_CLASS => 'class',
                T_INTERFACE => 'interface',
                T_TRAIT => 'trait',
                T_ENUM => 'enum',
                default => null,
            };

            if ($kind === null || $this->isAnonymousClass($tokens, $index)) {
                continue;
            }

            $name = $this->nextIdentifier($tokens, $index + 1);
            if ($name !== null) {
                $symbols[] = [
                    'name' => $namespace . $name,
                    'kind' => $kind,
                ];
            }
        }

        return $symbols;
    }

    /** @param list<array|string> $tokens */
    private function readNamespace(array $tokens, int &$index): string
    {
        $parts = [];
        for ($index++; isset($tokens[$index]); $index++) {
            if (is_string($tokens[$index]) && in_array($tokens[$index], [';', '{'], true)) {
                break;
            }
            if (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $parts[] = $tokens[$index][1];
            }
        }

        return implode('', $parts) . '\\';
    }

    /** @param list<array|string> $tokens */
    private function nextIdentifier(array $tokens, int $index): ?string
    {
        for (; isset($tokens[$index]); $index++) {
            if (is_array($tokens[$index]) && $tokens[$index][0] === T_STRING) {
                return $tokens[$index][1];
            }
            if (is_string($tokens[$index]) && $tokens[$index] === '{') {
                return null;
            }
        }

        return null;
    }

    /** @param list<array|string> $tokens */
    private function isAnonymousClass(array $tokens, int $index): bool
    {
        for ($index--; $index >= 0; $index--) {
            if (is_array($tokens[$index]) && trim($tokens[$index][1]) === '') {
                continue;
            }
            return is_array($tokens[$index]) && $tokens[$index][0] === T_NEW;
        }

        return false;
    }

    /** @param list<array{source: string, alias: string, kind: string}> $aliases */
    private function writeAliasFile(string $file, array $aliases): void
    {
        $contents = "<?php\n\ndeclare(strict_types=1);\n\n";
        foreach ($aliases as $alias) {
            $source = var_export($alias['source'], true);
            $target = var_export($alias['alias'], true);
            $checker = match ($alias['kind']) {
                'interface' => 'interface_exists',
                'trait' => 'trait_exists',
                'enum' => 'enum_exists',
                default => 'class_exists',
            };
            $contents .= "if ({$checker}({$source}) && ! {$checker}({$target})) { class_alias({$source}, {$target}); }\n";
        }

        file_put_contents($file, $contents);
    }

    /** @param list<array{source: string, target: string, target_prefix: string, kind: string, paths: list<string>}> $rebases */
    private function writeRebaseAutoloadFile(string $file, array $rebases): void
    {
        $mappings = [];
        foreach ($rebases as $rebase) {
            $mappings[$rebase['target_prefix']] = $rebase['paths'];
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\n";
        $contents .= '$mappings = ' . var_export($mappings, true) . ";\n";
        $contents .= <<<'PHP'
spl_autoload_register(static function (string $class) use ($mappings): void {
    foreach ($mappings as $prefix => $paths) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relativeClass = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        foreach ($paths as $path) {
            $file = $path . '/' . $relativeClass;
            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }
}, true, true);

PHP;

        foreach ($rebases as $rebase) {
            $source = var_export($rebase['source'], true);
            $target = var_export($rebase['target'], true);
            $checker = match ($rebase['kind']) {
                'interface' => 'interface_exists',
                'trait' => 'trait_exists',
                'enum' => 'enum_exists',
                default => 'class_exists',
            };
            $contents .= "if ({$checker}({$target}) && ! {$checker}({$source}, false)) { class_alias({$target}, {$source}); }\n";
        }

        file_put_contents($file, $contents);
    }

    /** @param list<array{source: string, alias: string, kind: string}> $aliases */
    private function writeContainerAliasesFile(string $file, array $aliases): void
    {
        $containerAliases = [];

        foreach ($aliases as $alias) {
            if (! in_array($alias['kind'], ['class', 'interface'], true)) {
                continue;
            }

            $containerAliases[$alias['source']] = $alias['alias'];
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($containerAliases, true).";\n";
        file_put_contents($file, $contents);
    }

    private function registerAutoloadFile(string $autoloadFiles, string $marker, string $file, bool $enabled): void
    {
        if (! is_file($autoloadFiles)) {
            return;
        }

        $contents = file_get_contents($autoloadFiles);
        if ($contents === false) {
            throw new RuntimeException('Unable to read Composer autoload files.');
        }

        $key = var_export($marker, true);
        $entry = "    {$key} => \$vendorDir . '/composer/{$file}',\n";
        $contents = preg_replace('/\s*' . preg_quote($key, '/') . '\s*=>[^\n]+,\n/', "\n", $contents) ?? $contents;

        if ($enabled) {
            $contents = preg_replace('/return array\s*\(\s*\n/', "return array(\n" . $entry, $contents, 1) ?? $contents;
        }

        file_put_contents($autoloadFiles, $contents);
    }
}
