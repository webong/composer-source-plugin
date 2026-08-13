<?php

declare(strict_types=1);

namespace Webong\ComposerNamespaceAlias;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class NamespaceAliasGenerator
{
    private const EXTRA_KEY = 'namespace-alias';
    private const AUTOLOAD_FILE = 'namespace_aliases.php';
    private const AUTOLOAD_FILES_MARKER = 'webong/composer-namespace-alias';

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
        $aliases = [];

        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getPackages() as $package) {
            $aliases = array_merge($aliases, $this->aliasesForPackage($package));
        }

        $this->writeAliasFile($generatedFile, $aliases);
        $this->registerAutoloadFile($autoloadFiles, $generatedFile, $aliases !== []);

        if ($aliases !== []) {
            $this->io->writeError(sprintf('<info>Generated %d namespace aliases.</info>', count($aliases)));
        }
    }

    /** @return list<array{source: string, alias: string, kind: string}> */
    private function aliasesForPackage(PackageInterface $package): array
    {
        $configured = $package->getExtra()[self::EXTRA_KEY] ?? [];

        if (! is_array($configured) || $configured === []) {
            return [];
        }

        $installPath = $this->composer->getInstallationManager()->getInstallPath($package);
        if (! is_string($installPath) || ! is_dir($installPath)) {
            return [];
        }

        $aliases = [];
        foreach ($configured as $sourcePrefix => $aliasPrefix) {
            if (! is_string($sourcePrefix) || ! is_string($aliasPrefix)) {
                continue;
            }

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

    /** @return list<array{name: string, kind: string}> */
    private function discoverSymbols(string $directory): array
    {
        $symbols = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $symbols = array_merge($symbols, $this->symbolsInFile($file->getPathname()));
        }

        return $symbols;
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

    private function registerAutoloadFile(string $autoloadFiles, string $generatedFile, bool $enabled): void
    {
        if (! is_file($autoloadFiles)) {
            return;
        }

        $contents = file_get_contents($autoloadFiles);
        if ($contents === false) {
            throw new RuntimeException('Unable to read Composer autoload files.');
        }

        $key = var_export(self::AUTOLOAD_FILES_MARKER, true);
        $entry = "    {$key} => \$vendorDir . '/composer/" . self::AUTOLOAD_FILE . "',\n";
        $contents = preg_replace('/\s*' . preg_quote($key, '/') . '\s*=>[^\n]+,\n/', "\n", $contents) ?? $contents;

        if ($enabled) {
            $contents = preg_replace('/return array \(\n/', "return array (\n" . $entry, $contents, 1) ?? $contents;
        }

        file_put_contents($autoloadFiles, $contents);
    }
}
