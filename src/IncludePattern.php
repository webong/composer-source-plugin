<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use InvalidArgumentException;

/**
 * A package-relative include pattern.
 *
 * Supported glob tokens are `*` (one path segment), `**` (any path) and `?`
 * (one non-separator character). Character classes such as `[a-z]` are
 * supported. A pattern that names a directory includes that directory's whole
 * subtree, so `src/Core` and `src/Core/**` are equivalent for file selection.
 */
final class IncludePattern
{
    /** @var array<string, string> */
    private static array $compiled = [];

    public static function normalize(string $path): string
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException('An empty include path is not allowed.');
        }

        $path = trim($path);

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('An include path cannot contain a null byte.');
        }

        if (str_contains($path, '\\')) {
            throw new InvalidArgumentException(sprintf(
                'Include path [%s] must use forward slashes, not backslashes.',
                $path,
            ));
        }

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            throw new InvalidArgumentException(sprintf(
                'Include path [%s] is absolute; paths must be package-relative.',
                $path,
            ));
        }

        if (str_contains($path, ':')) {
            throw new InvalidArgumentException(sprintf(
                'Include path [%s] cannot contain a colon; paths must be package-relative.',
                $path,
            ));
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new InvalidArgumentException(sprintf(
                    'Include path [%s] must stay inside the package; ".." traversal is not allowed.',
                    $path,
                ));
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new InvalidArgumentException(sprintf(
                'An include path cannot be empty: [%s].',
                $path,
            ));
        }

        $normalized = implode('/', $segments);
        self::compile($normalized);

        return $normalized;
    }

    public static function matches(string $pattern, string $path): bool
    {
        $regex = self::compile($pattern);
        if (preg_match($regex, $path) === 1) {
            return true;
        }

        $segments = explode('/', $path);
        $count = count($segments);
        for ($length = 1; $length < $count; $length++) {
            if (preg_match($regex, implode('/', array_slice($segments, 0, $length))) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    public static function matchesAny(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private static function compile(string $pattern): string
    {
        if (isset(self::$compiled[$pattern])) {
            return self::$compiled[$pattern];
        }

        $regex = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === '*') {
                if ($index + 1 < $length && $pattern[$index + 1] === '*') {
                    while ($index + 1 < $length && $pattern[$index + 1] === '*') {
                        $index++;
                    }
                    $regex .= '.*';

                    continue;
                }

                $regex .= '[^/]*';

                continue;
            }

            if ($character === '?') {
                $regex .= '[^/]';

                continue;
            }

            if ($character === '[') {
                $end = strpos($pattern, ']', $index + 1);
                if ($end === false) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid glob pattern [%s]: unterminated character class.',
                        $pattern,
                    ));
                }

                $class = substr($pattern, $index + 1, $end - $index - 1);
                if ($class === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid glob pattern [%s]: empty character class.',
                        $pattern,
                    ));
                }

                if (str_contains($class, '[') || str_contains($class, '\\') || str_contains($class, '/')) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid glob pattern [%s]: unsupported character class.',
                        $pattern,
                    ));
                }

                $negated = $class[0] === '!' || $class[0] === '^';
                if ($negated) {
                    $class = substr($class, 1);
                    if ($class === '') {
                        throw new InvalidArgumentException(sprintf(
                            'Invalid glob pattern [%s]: empty character class.',
                            $pattern,
                        ));
                    }
                }

                $regex .= '[' . ($negated ? '^' : '') . $class . ']';
                $index = $end;

                continue;
            }

            if ($character === ']') {
                $regex .= '\]';

                continue;
            }

            $regex .= preg_quote($character, '/');
        }

        return self::$compiled[$pattern] = '#^' . $regex . '$#D';
    }
}
