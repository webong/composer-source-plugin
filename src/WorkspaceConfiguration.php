<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\Factory;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * Reads and validates `extra.source-plugin.workspaces`.
 *
 * A workspace is a local package directory. Every matched directory becomes a
 * canonical path repository (see WorkspaceRepository), so the local copy wins
 * over the registry exactly like an npm workspace link.
 *
 * Version contract: a workspace must declare `version` unless the requirement
 * that points at it is `@dev`, `dev-*`, or `*`. Composer has no `workspace:`
 * protocol — its parser rejects that literal before plugins activate — so this
 * is the closest honest equivalent, and a mismatch is reported rather than
 * silently widened.
 */
final class WorkspaceConfiguration
{
    /** @var \WeakMap<Composer, array<string, WorkspaceDefinition>>|null */
    private static ?\WeakMap $discovered = null;

    /**
     * Results are memoized per Composer instance: project and autoload events
     * run once per installed package, and re-globbing the tree each time would
     * turn a large install into thousands of directory reads.
     *
     * @return array<string, WorkspaceDefinition> keyed by package name
     */
    public static function discover(Composer $composer): array
    {
        self::$discovered ??= new \WeakMap();

        if (! isset(self::$discovered[$composer])) {
            $raw = SourcePluginConfig::workspaces($composer);
            self::$discovered[$composer] = $raw === []
                ? []
                : self::parse($raw, self::rootDirectory($composer));
        }

        return self::$discovered[$composer];
    }

    /**
     * @param array<string, mixed> $workspaces raw extra.source-plugin.workspaces
     * @return array<string, WorkspaceDefinition> keyed by package name
     *
     * @throws InvalidArgumentException
     */
    public static function parse(array $workspaces, string $rootDir): array
    {
        $config = self::normalize($workspaces);
        if ($config['include'] === []) {
            return [];
        }

        $root = realpath($rootDir);
        if ($root === false) {
            throw new InvalidArgumentException(sprintf('Workspace root [%s] does not exist.', $rootDir));
        }

        $definitions = [];
        $seen = [];

        foreach ($config['include'] as $pattern) {
            foreach (self::expand($root, $pattern) as $relative) {
                if (isset($seen[$relative]) || self::isExcluded($relative, $config['exclude'])) {
                    continue;
                }
                $seen[$relative] = true;

                $definition = self::define($root, $relative, $pattern, $config['symlink']);
                if ($definition === null) {
                    continue;
                }

                if (isset($definitions[$definition->package])) {
                    throw new InvalidArgumentException(sprintf(
                        'Workspace [%s] is declared by both %s and %s.',
                        $definition->package,
                        $definitions[$definition->package]->path,
                        $definition->path,
                    ));
                }

                $definitions[$definition->package] = $definition;
            }
        }

        ksort($definitions);

        return $definitions;
    }

    /**
     * Workspace packages behave like loaders for conflict validation: a mirror
     * needs the remote package installed, and a projection deletes files from
     * the installed package, so neither can apply to a local source of truth.
     *
     * @param array<string, WorkspaceDefinition> $workspaces
     * @return array<string, array{type: string, path: string}>
     */
    public static function loaderConfiguration(array $workspaces): array
    {
        $loaders = [];
        foreach ($workspaces as $workspace) {
            $loaders[$workspace->package] = [
                'type' => 'inline',
                'path' => $workspace->path,
            ];
        }

        return $loaders;
    }

    /**
     * Drop workspaces the consumer explicitly routed back to the published
     * package with a loaders entry of type "outline". Those keep the registry
     * source, so their local version must not be validated or linked.
     *
     * @param array<string, WorkspaceDefinition> $workspaces
     * @param array<string, mixed> $loaders
     * @return array<string, WorkspaceDefinition>
     */
    public static function active(array $workspaces, array $loaders): array
    {
        $explicit = [];
        foreach ($loaders as $package => $configuration) {
            if (is_string($package) && is_array($configuration)) {
                $explicit[strtolower($package)] = $configuration['type'] ?? 'auto';
            }
        }

        $active = [];
        foreach ($workspaces as $package => $workspace) {
            if (($explicit[$package] ?? null) === 'outline') {
                continue;
            }
            $active[$package] = $workspace;
        }

        return $active;
    }

    /**
     * Fail before the solver produces its generic "could not find a matching
     * version" message when a local version cannot satisfy a requirement.
     *
     * @param array<string, string> $rootRequires package name => pretty constraint
     * @param array<string, WorkspaceDefinition> $workspaces
     *
     * @throws InvalidArgumentException
     */
    public static function assertSatisfiable(array $rootRequires, array $workspaces): void
    {
        $problems = self::problems($rootRequires, $workspaces);
        if ($problems !== []) {
            throw new InvalidArgumentException($problems[0]);
        }
    }

    /**
     * Non-throwing form of assertSatisfiable(), so `composer source:workspaces`
     * can report every problem in one pass instead of only the first.
     *
     * @param array<string, string> $rootRequires package name => pretty constraint
     * @param array<string, WorkspaceDefinition> $workspaces
     * @return list<string>
     */
    public static function problems(array $rootRequires, array $workspaces): array
    {
        $problems = [];

        foreach ($rootRequires as $package => $constraint) {
            $workspace = $workspaces[strtolower($package)] ?? null;
            if ($workspace === null) {
                continue;
            }

            $problem = self::constraintProblem('the root', $workspace, (string) $constraint);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        foreach ($workspaces as $definition) {
            foreach ($definition->requires as $package => $constraint) {
                $workspace = $workspaces[strtolower($package)] ?? null;
                if ($workspace === null) {
                    continue;
                }

                $problem = self::constraintProblem($definition->package, $workspace, $constraint);
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        }

        return $problems;
    }

    /**
     * @return array<string, string> package name => pretty constraint
     */
    public static function composerRequires(Composer $composer): array
    {
        $package = $composer->getPackage();
        $requires = [];

        foreach (array_merge($package->getRequires(), $package->getDevRequires()) as $name => $link) {
            $requires[strtolower($name)] = $link->getPrettyConstraint();
        }

        return $requires;
    }

    public static function rootDirectory(Composer $composer): string
    {
        $manifest = Factory::getComposerFile();
        $directory = dirname($manifest);
        $resolved = realpath($directory);

        return $resolved === false ? (string) getcwd() : $resolved;
    }

    /**
     * @param array<string, mixed> $workspaces
     * @return array{include: list<string>, exclude: list<string>, symlink: bool}
     */
    private static function normalize(array $workspaces): array
    {
        $include = [];
        $exclude = [];
        $symlink = true;

        if (array_is_list($workspaces)) {
            $include = $workspaces;
        } elseif (isset($workspaces['include'])) {
            foreach (array_keys($workspaces) as $key) {
                if (! in_array($key, ['include', 'exclude', 'symlink'], true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Workspaces has an unsupported key [%s]; only "include", "exclude", and "symlink" are accepted.',
                        (string) $key,
                    ));
                }
            }

            $include = $workspaces['include'];
            $exclude = $workspaces['exclude'] ?? [];

            if (array_key_exists('symlink', $workspaces)) {
                if (! is_bool($workspaces['symlink'])) {
                    throw new InvalidArgumentException('Workspaces "symlink" must be a boolean.');
                }
                $symlink = $workspaces['symlink'];
            }
        } else {
            throw new InvalidArgumentException(
                'Workspaces must be a list of globs or an object with an "include" list.',
            );
        }

        if (! is_array($include) || ! array_is_list($include) || $include === []) {
            throw new InvalidArgumentException('Workspaces "include" must be a non-empty list of package globs.');
        }
        if (! is_array($exclude) || ! array_is_list($exclude)) {
            throw new InvalidArgumentException('Workspaces "exclude" must be a list of package globs.');
        }

        return [
            'include' => self::patterns($include, 'include'),
            'exclude' => self::patterns($exclude, 'exclude'),
            'symlink' => $symlink,
        ];
    }

    /**
     * @param array<mixed> $patterns
     * @return list<string>
     */
    private static function patterns(array $patterns, string $key): array
    {
        $valid = [];
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                throw new InvalidArgumentException(sprintf('Workspaces "%s" entries must be non-empty strings.', $key));
            }

            $pattern = trim($pattern);
            if (! SourceLock::isSafeRelativePath($pattern)) {
                throw new InvalidArgumentException(sprintf(
                    'Workspace pattern [%s] must be package-relative: no absolute paths, "..", backslashes, or colons.',
                    $pattern,
                ));
            }

            $valid[] = trim($pattern, '/');
        }

        if ($valid === [] && $key === 'exclude') {
            return [];
        }

        return $valid;
    }

    /**
     * @return list<string> root-relative directories using forward slashes
     */
    private static function expand(string $root, string $pattern): array
    {
        $pattern = trim($pattern, '/');

        if (strpbrk($pattern, '*?[') === false) {
            if (! is_dir($root . '/' . $pattern)) {
                throw new InvalidArgumentException(sprintf(
                    'Workspace [%s] does not exist. Check extra.source-plugin.workspaces.',
                    $pattern,
                ));
            }

            return [$pattern];
        }

        if (str_contains($pattern, '**')) {
            if (! str_ends_with($pattern, '/**')) {
                throw new InvalidArgumentException(sprintf(
                    'Workspace pattern [%s] may only use "**" as a trailing segment, for example packages/**.',
                    $pattern,
                ));
            }

            $directories = [];
            foreach (self::expandSimple($root, substr($pattern, 0, -3)) as $base) {
                $directories = array_merge($directories, self::descend($root, $base));
            }

            sort($directories);

            return $directories;
        }

        return self::expandSimple($root, $pattern);
    }

    /**
     * @return list<string>
     */
    private static function expandSimple(string $root, string $pattern): array
    {
        $pattern = trim($pattern, '/');

        if (strpbrk($pattern, '*?[') === false) {
            return [$pattern];
        }

        $matches = glob($root . '/' . $pattern, GLOB_ONLYDIR);
        if ($matches === false) {
            return [];
        }

        $directories = [];
        foreach ($matches as $match) {
            $relative = self::relative($root, $match);
            if ($relative !== null) {
                $directories[] = $relative;
            }
        }
        sort($directories);

        return $directories;
    }

    /**
     * @return list<string>
     */
    private static function descend(string $root, string $base): array
    {
        $found = [];
        $stack = [$base];

        while ($stack !== []) {
            $current = array_pop($stack);
            $absolute = $root . '/' . $current;
            $entries = @scandir($absolute);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $relative = $current . '/' . $entry;
                $path = $root . '/' . $relative;
                if (! is_dir($path) || is_link($path)) {
                    continue;
                }
                if ($entry[0] === '.' || $entry === 'vendor' || $entry === 'node_modules') {
                    continue;
                }

                $found[] = $relative;
                $stack[] = $relative;
            }
        }

        return $found;
    }

    private static function define(
        string $root,
        string $relative,
        string $pattern,
        bool $symlink,
    ): ?WorkspaceDefinition {
        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
            if ($segment[0] === '.' || $segment === 'vendor' || $segment === 'node_modules') {
                return null;
            }
        }

        $absolute = realpath($root . '/' . $relative);
        if ($absolute === false || ! str_starts_with($absolute . '/', $root . '/')) {
            return null;
        }

        $manifest = $absolute . '/composer.json';
        if (! is_file($manifest)) {
            $literal = strpbrk($pattern, '*?[') === false;
            if ($literal) {
                throw new InvalidArgumentException(sprintf(
                    'Workspace [%s] has no composer.json. Check extra.source-plugin.workspaces.',
                    $relative,
                ));
            }

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf(
                'Workspace [%s] has an invalid composer.json: %s',
                $relative,
                $exception->getMessage(),
            ));
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('Workspace [%s] has an invalid composer.json.', $relative));
        }

        $package = $decoded['name'] ?? null;
        if (! is_string($package) || preg_match(ProjectionDefinition::PACKAGE_PATTERN, strtolower(trim($package))) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Workspace [%s] must declare a valid package name such as acme/core in composer.json.',
                $relative,
            ));
        }

        return new WorkspaceDefinition(
            package: strtolower(trim($package)),
            path: $relative,
            version: self::version($decoded['version'] ?? null, $relative),
            requires: self::requires($decoded['require'] ?? null),
            symlink: $symlink,
        );
    }

    private static function version(mixed $version, string $relative): ?string
    {
        if ($version === null) {
            return null;
        }

        if (! is_string($version) || trim($version) === '') {
            throw new InvalidArgumentException(sprintf(
                'Workspace [%s] must declare "version" as a non-empty string or omit it.',
                $relative,
            ));
        }

        $version = trim($version);

        try {
            (new VersionParser())->normalize($version);
        } catch (UnexpectedValueException $exception) {
            throw new InvalidArgumentException(sprintf(
                'Workspace [%s] declares an invalid version [%s]: %s',
                $relative,
                $version,
                $exception->getMessage(),
            ));
        }

        return $version;
    }

    /**
     * @return array<string, string>
     */
    private static function requires(mixed $requires): array
    {
        if (! is_array($requires)) {
            return [];
        }

        $valid = [];
        foreach ($requires as $package => $constraint) {
            if (is_string($package) && is_string($constraint)) {
                $valid[strtolower($package)] = $constraint;
            }
        }

        return $valid;
    }

    private static function constraintProblem(
        string $requiredBy,
        WorkspaceDefinition $workspace,
        string $constraint,
    ): ?string {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === 'self.version') {
            return null;
        }

        // Stability flags and branch constraints need no local version.
        if ($constraint[0] === '@' || $constraint === '*' || str_starts_with($constraint, 'dev-')) {
            return null;
        }

        if ($workspace->version === null) {
            return sprintf(
                'Workspace [%s] does not declare a version, but %s requires %s. Add "version" to %s/composer.json, or require it as @dev or *.',
                $workspace->package,
                $requiredBy,
                $constraint,
                $workspace->path,
            );
        }

        try {
            $satisfied = Semver::satisfies($workspace->version, $constraint);
        } catch (UnexpectedValueException) {
            // An unparseable constraint is the solver's error to report.
            return null;
        }

        if ($satisfied) {
            return null;
        }

        return sprintf(
            'Workspace [%s] is %s, but %s requires %s. Align the requirement or the workspace version.',
            $workspace->package,
            $workspace->version,
            $requiredBy,
            $constraint,
        );
    }

    /**
     * @param list<string> $exclude
     */
    private static function isExcluded(string $relative, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if (self::matchesPattern($pattern, $relative)
                || str_starts_with($relative . '/', rtrim($pattern, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deliberately small: `*` matches within one segment and `?` matches one
     * character. fnmatch() is not available on every platform Composer runs on.
     */
    private static function matchesPattern(string $pattern, string $path): bool
    {
        $regex = '#^' . str_replace(
            ['\*', '\?'],
            ['[^/]*', '[^/]'],
            preg_quote($pattern, '#'),
        ) . '$#';

        return preg_match($regex, $path) === 1;
    }

    private static function relative(string $root, string $absolute): ?string
    {
        $real = realpath($absolute);
        if ($real === false || ! str_starts_with($real . '/', $root . '/')) {
            return null;
        }

        $relative = substr($real, strlen($root) + 1);

        return str_replace('\\', '/', $relative);
    }
}
