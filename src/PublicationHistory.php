<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Semver\VersionParser;

/** Immutable existing index records and filename compatibility for one command. */
final class PublicationHistory
{
    public const INDEX = 'composer-packages.json';

    private ?array $previous = null;
    public readonly ?string $reference;
    /** @var list<string> */
    public readonly array $filenames;

    public function __construct(private readonly PublicationGit $git, string $branch, ?string $alias = null)
    {
        if ($alias !== null && (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.json$/D', $alias) !== 1 || $alias === 'composer.json')) {
            throw new PublicationException('Metadata alias must be a plain JSON filename other than composer.json.');
        }
        $this->reference = $git->ref('refs/heads/'.$branch);
        $allowed = array_values(array_unique([self::INDEX, 'packages.json', ...($alias === null ? [] : [$alias])]));
        $names = array_values(array_unique([self::INDEX, ...($alias === null ? [] : [$alias])]));
        if ($this->reference !== null) {
            $files = explode("\n", $git->out(['ls-tree', '--name-only', $this->reference]));
            if (array_diff($files, ['README.md', ...$allowed]) !== []) {
                throw new PublicationException('Metadata branch already contains unrelated files; refusing to replace it.');
            }
            $found = false;
            foreach ($allowed as $filename) {
                if (! in_array($filename, $files, true)) {
                    continue;
                }
                $document = json_decode($git->out(['show', $this->reference.':'.$filename]), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($document) || ! is_array($document['packages'] ?? null)) {
                    throw new PublicationException('Existing metadata file has no packages map: '.$filename);
                }
                if ($this->previous !== null && $this->canonical($document) !== $this->canonical($this->previous)) {
                    throw new PublicationException('Existing metadata aliases disagree; reconcile the index before publishing.');
                }
                $found = true;
                $this->previous = $document;
                $names[] = $filename;
            }
            if (! $found) {
                throw new PublicationException('Metadata branch has no recognized package index; refusing to replace it.');
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);
        $this->filenames = $names;
    }

    /** Carry forward exact stable records, including retired workspace packages. */
    public function merge(array $document): array
    {
        if ($this->previous === null) {
            return $document;
        }
        $parser = new VersionParser();
        foreach ($this->previous['packages'] as $name => $versions) {
            if (! is_string($name) || ! is_array($versions)) {
                throw new PublicationException('Existing package index contains an invalid package map.');
            }
            $seen = [];
            foreach ($versions as $version => $record) {
                $version = (string) $version;
                if ($parser->parseStability($version) === 'dev') {
                    continue; // Development records describe current generated branches.
                }
                $normalized = $parser->normalize($version);
                if (isset($seen[$normalized]) || ! is_array($record) || ($record['name'] ?? null) !== $name
                    || ! is_string($record['version'] ?? null) || $parser->normalize($record['version']) !== $normalized) {
                    throw new PublicationException('Existing package index contains an ambiguous stable identity: '.$name.' '.$version);
                }
                $seen[$normalized] = true;
                foreach ($document['packages'][$name] ?? [] as $candidateVersion => $candidate) {
                    if ($parser->normalize((string) $candidateVersion) !== $normalized) {
                        continue;
                    }
                    foreach (['source', 'dist'] as $transport) {
                        $old = $record[$transport]['reference'] ?? null;
                        $new = $candidate[$transport]['reference'] ?? null;
                        if ($old !== null && $new !== null && $old !== $new) {
                            throw new PublicationException('Published version '.$name.' '.$version.' has a different '.$transport.' reference; refusing to replace it.');
                        }
                    }
                    unset($document['packages'][$name][$candidateVersion]);
                }
                $document['packages'][$name][$version] = $record;
            }
        }
        // Preserve existing root metadata (for example notification URLs).
        return array_replace($this->previous, $document);
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }
            return array_map($sort, $item);
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
