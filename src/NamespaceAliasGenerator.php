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
    private const AUTOLOAD_FILE = 'namespace_aliases.php';
    private const REBASE_AUTOLOAD_FILE = 'namespace_rebases.php';
    private const CONTAINER_ALIASES_FILE = 'source_aliases.php';

    /** @var array<string, true> */
    private array $preparedRoots = [];

    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
    ) {
    }

    public function generate(): void
    {
        $this->preparedRoots = [];
        $vendorDirectory = $this->composer->getConfig()->get('vendor-dir');
        $generatedFile = $vendorDirectory . '/composer/' . self::AUTOLOAD_FILE;
        $rebaseGeneratedFile = $vendorDirectory . '/composer/' . self::REBASE_AUTOLOAD_FILE;
        $containerAliasesFile = $vendorDirectory . '/composer/' . self::CONTAINER_ALIASES_FILE;
        $aliases = [];
        $rebases = [];

        $configured = $this->configuredAliases();

        foreach ($this->localPackages() as $package) {
            $aliases = array_merge($aliases, $this->aliasesForPackage($package, $configured));
            $rebases = array_merge($rebases, $this->rebasesForPackage($package, $configured, $vendorDirectory));
        }

        $this->writeAliasFile($generatedFile, $aliases);
        $this->writeRebaseAutoloadFile($rebaseGeneratedFile, $rebases);
        $this->writeContainerAliasesFile($containerAliasesFile, $aliases);

        // The generated files are loaded by src/bootstrap.php, which is
        // registered as this package's own "files" autoload entry. Patching
        // the generated autoloader is not viable: Composer omits the
        // files-loading section entirely when no package declares one.

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
        return NamespaceAliasConfiguration::parse(SourcePluginConfig::aliases($this->composer));
    }

    /**
     * @param list<NamespaceAliasDefinition> $configured
     * @return list<array{source: string, target: string, target_prefix: string, kind: string, paths: list<string>, files: list<string>}>
     */
    private function rebasesForPackage(PackageInterface $package, array $configured, string $vendorDirectory): array
    {
        $installPath = $this->sourceRoot($package);
        if ($installPath === null) {
            return [];
        }

        $rebases = [];
        foreach ($configured as $definition) {
            if ($definition->type !== NamespaceAliasDefinition::TYPE_REBASE || ! $definition->appliesTo($package->getName())) {
                continue;
            }

            $rebased = $this->rebasePackage($package, $installPath, $vendorDirectory, $definition);
            if ($rebased['paths'] === []) {
                continue;
            }

            $symbolPrefix = $definition->sourcePrefix;
            $symbolRoot = $installPath;
            if ($definition->destination === NamespaceAliasDefinition::DESTINATION_MIRROR) {
                $installedPath = $this->composer->getInstallationManager()->getInstallPath($package);
                if (is_string($installedPath) && is_dir($installedPath)) {
                    $symbolRoot = $installedPath;
                }
            }
            foreach ($this->discoverSymbols($symbolRoot, $rebased['selected'], $definition->exclude) as $symbol) {
                if (! str_starts_with($symbol['name'], $symbolPrefix)) {
                    continue;
                }

                $suffix = substr($symbol['name'], strlen($symbolPrefix));

                $rebases[] = [
                    'source' => $definition->sourcePrefix . $suffix,
                    'target' => $definition->targetPrefix . $suffix,
                    'target_prefix' => $definition->targetPrefix,
                    'kind' => $symbol['kind'],
                    'paths' => $rebased['paths'],
                    'files' => $rebased['files'],
                ];
            }
        }

        return $rebases;
    }

    /**
     * Composer can hold more than one representation of the same installed
     * package (a resolved version plus an unresolved dev alias), all pointing
     * at the same directory. Processing one per install path keeps the
     * generated aliases free of duplicates and avoids walking the same tree
     * twice.
     *
     * @return list<PackageInterface>
     */
    private function localPackages(): array
    {
        $installationManager = $this->composer->getInstallationManager();
        $packages = [];
        $seen = [];

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $installPath = $installationManager->getInstallPath($package);
            $key = (is_string($installPath) ? $installPath : $package->getName()) . '|' . $package->getName();

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $packages[] = $package;
        }

        return $packages;
    }

    /**
     * Where a package's rebaseable source actually lives.
     *
     * A mirrored package is installed under vendor/ but developed in its mirror
     * working copy, so the mirror path wins. Everything else falls back to the
     * install path.
     */
    private function sourceRoot(PackageInterface $package): ?string
    {
        $mirror = $this->mirrorPath($package->getName());
        if ($mirror !== null && is_dir($mirror)) {
            return $mirror;
        }

        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);

        return is_string($installPath) && is_dir($installPath) ? $installPath : null;
    }

    private function mirrorPath(string $packageName): ?string
    {
        $mirrors = $this->mirrors();
        if (! isset($mirrors[$packageName])) {
            return null;
        }

        $path = str_replace('\\', '/', $mirrors[$packageName]);
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim((string) getcwd(), '/') . '/' . ltrim($path, '/');
    }

    /** @return array<string, string> package name => configured mirror path */
    private function mirrors(): array
    {
        $mirrors = SourcePluginConfig::mirrors($this->composer);

        $paths = [];
        foreach ($mirrors as $packageName => $configuration) {
            if (! is_string($packageName)) {
                continue;
            }

            $path = is_array($configuration) ? ($configuration['path'] ?? null) : $configuration;
            if (is_string($path) && $path !== '') {
                $paths[$packageName] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return array{paths: list<string>, files: list<string>, selected: array|null}
     */
    private function rebasePackage(PackageInterface $package, string $installPath, string $vendorDirectory, NamespaceAliasDefinition $definition): array
    {
        $autoload = $package->getAutoload();
        $sourceDirectories = $autoload['psr-4'][$definition->sourcePrefix] ?? [];
        if (! is_array($sourceDirectories)) {
            $sourceDirectories = [$sourceDirectories];
        }

        $paths = [];
        $rebasedRelativeRoot = 'rebased/' . str_replace('/', '--', $package->getName());
        $rebasedRoot = $vendorDirectory . '/composer/' . $rebasedRelativeRoot;
        $selected = $definition->copy === 'autoload'
            ? array_merge($sourceDirectories, $autoload['files'] ?? [], $definition->include)
            : null;
        $this->rebasePackageFiles($installPath, $rebasedRoot, $definition, $selected, $definition->exclude);

        foreach ($sourceDirectories as $sourceDirectory) {
            if (! is_string($sourceDirectory)) {
                continue;
            }

            $sourcePath = $installPath . '/' . trim($sourceDirectory, '/');
            if (! is_dir($sourcePath)) {
                continue;
            }

            $paths[] = $rebasedRelativeRoot . '/' . trim($sourceDirectory, '/');
        }

        return [
            'paths' => $paths,
            'selected' => $selected,
            'files' => array_values(array_filter(
                $this->rebasedAutoloadFiles($autoload['files'] ?? [], $installPath, $rebasedRelativeRoot, $definition),
                static fn (string $file): bool => is_file($vendorDirectory . '/composer/' . $file),
            )),
        ];
    }

    /**
     * Composer's "files" autoload entries are not covered by the rebased PSR-4
     * autoloader, so their rebased copies have to be required explicitly for the
     * target namespace to expose the same functions. Entries that do not
     * declare the source namespace are skipped: their rebased copy would be
     * identical and requiring it would redeclare global symbols.
     *
     * @param mixed $files
     * @return list<string>
     */
    private function rebasedAutoloadFiles(mixed $files, string $installPath, string $rebasedRelativeRoot, NamespaceAliasDefinition $definition): array
    {
        if (! is_array($files)) {
            return [];
        }

        $rebased = [];
        foreach ($files as $file) {
            if (! is_string($file) || $file === '') {
                continue;
            }

            $contents = @file_get_contents($installPath . '/' . ltrim($file, '/'));
            $namespace = $definition->destination === NamespaceAliasDefinition::DESTINATION_MIRROR
                ? $definition->targetPrefix
                : $definition->sourcePrefix;
            if ($contents === false || ! str_contains($contents, rtrim($namespace, '\\'))) {
                continue;
            }

            $rebased[] = $rebasedRelativeRoot . '/' . ltrim($file, '/');
        }

        return $rebased;
    }

    private function rebasePackageFiles(string $sourcePath, string $rebasedPath, NamespaceAliasDefinition $definition, ?array $selected = null, array $excluded = []): void
    {
        if ($selected !== null && ! isset($this->preparedRoots[$rebasedPath])) {
            // A consumer switching policies must not retain yesterday's copied
            // metadata or deleted classes. Clear this generated tree once, so
            // multiple namespace definitions can populate it in the same dump.
            $this->clearGeneratedTree($rebasedPath);
            $this->preparedRoots[$rebasedPath] = true;
        }
        $files = $this->packageFiles($sourcePath, $selected, $excluded);
        $rebaser = new NamespaceRebaser;

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relativePath = ltrim(str_replace($sourcePath, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $target = $rebasedPath . DIRECTORY_SEPARATOR . $relativePath;
            $targetDirectory = dirname($target);
            if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0777, true) && ! is_dir($targetDirectory)) {
                throw new RuntimeException('Unable to create rebased source directory: ' . $targetDirectory);
            }

            if ($file->getExtension() !== 'php') {
                if (! copy($file->getPathname(), $target)) {
                    throw new RuntimeException('Unable to copy package file: ' . $file->getPathname());
                }

                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                throw new RuntimeException('Unable to read PHP file: ' . $file->getPathname());
            }

            file_put_contents($target, $rebaser->rebase($contents, $definition));
        }
    }

    private function pathWithin(string $path, string $root): bool
    {
        $normalize = static fn (string $value): string => implode('/', array_filter(explode('/', str_replace('\\', '/', $value)), static fn (string $part): bool => $part !== '' && $part !== '.'));
        $path = $normalize($path);
        $root = $normalize($root);
        return $root === '' || $path === $root || str_starts_with($path, $root . '/');
    }

    private function packageFiles(string $sourcePath, ?array $selected = null, array $excluded = []): RecursiveIteratorIterator
    {
        // Prune complete subtrees consistently for copying and symbol discovery.
        $directory = new RecursiveDirectoryIterator($sourcePath, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, function ($file) use ($sourcePath, $selected, $excluded): bool {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($sourcePath, '/\\')) + 1));
            foreach ($excluded as $path) {
                if ($this->pathWithin($relative, $path)) {
                    return false;
                }
            }
            if ($selected === null) {
                return ! $this->isExcludedPath($file->getPathname(), $sourcePath);
            }
            foreach ($selected as $path) {
                if (is_string($path) && ($this->pathWithin($relative, $path) || ($file->isDir() && $this->pathWithin($path, $relative)))) {
                    return true;
                }
            }
            return false;
        });
        return new RecursiveIteratorIterator($filter);
    }

    private function clearGeneratedTree(string $root): void
    {
        if (is_link($root)) {
            throw new RuntimeException('Rebased output directory must not be a symlink: ' . $root);
        }
        if (! is_dir($root)) {
            return;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $removed = $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            if (! $removed) {
                throw new RuntimeException('Unable to remove stale rebased output: ' . $file->getPathname());
            }
        }
    }

    /** @return list<array{name: string, kind: string}> */
    private function discoverSymbols(string $directory, ?array $selected = null, array $excluded = []): array
    {
        $symbols = [];
        $files = $this->packageFiles($directory, $selected, $excluded);

        foreach ($files as $file) {
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
        $separator = preg_quote(DIRECTORY_SEPARATOR, '/');

        return preg_match('/^(?:tests?|vendor|build|dist)(?:'.$separator.'|$)/i', $relative) === 1
            || preg_match('/(?:'.$separator.'|^)phpstan(?:'.$separator.'|$)/i', $relative) === 1;
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

    /** @param list<array{source: string, target: string, target_prefix: string, kind: string, paths: list<string>, files: list<string>}> $rebases */
    private function writeRebaseAutoloadFile(string $file, array $rebases): void
    {
        $mappings = [];
        $autoloadFiles = [];
        $aliases = [];
        foreach ($rebases as $rebase) {
            $mappings[$rebase['target_prefix']] = $rebase['paths'];
            $autoloadFiles = [...$autoloadFiles, ...$rebase['files']];
            $aliases[$rebase['source']] = [
                'target' => $rebase['target'],
                'checker' => match ($rebase['kind']) {
                    'interface' => 'interface_exists',
                    'trait' => 'trait_exists',
                    'enum' => 'enum_exists',
                    default => 'class_exists',
                },
            ];
        }
        $autoloadFiles = array_values(array_unique($autoloadFiles));

        $contents = "<?php\n\ndeclare(strict_types=1);\n\n";
        $contents .= '$mappings = ' . var_export($mappings, true) . ";\n";
        $contents .= '$aliases = ' . var_export($aliases, true) . ";\n";
        $contents .= <<<'PHP'
spl_autoload_register(static function (string $class) use ($mappings, $aliases): void {
    foreach ($mappings as $prefix => $paths) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relativeClass = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        foreach ($paths as $path) {
            $file = __DIR__ . '/' . $path . '/' . $relativeClass;
            if (is_file($file)) {
                require $file;

                return;
            }
        }
    }

    if (! isset($aliases[$class])) {
        return;
    }

    $alias = $aliases[$class];
    $checker = $alias['checker'];
    if ($checker($alias['target']) && ! $checker($class, false)) {
        class_alias($alias['target'], $class);
    }
}, true, true);

PHP;

        foreach ($autoloadFiles as $autoloadFile) {
            $contents .= "require_once __DIR__ . '/" . $autoloadFile . "';\n";
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
}
