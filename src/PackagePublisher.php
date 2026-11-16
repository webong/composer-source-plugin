<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final class PackagePublisher
{
    private const ROOT_ONLY_KEYS = ['require-dev', 'autoload-dev', 'repositories', 'config', 'scripts', 'scripts-descriptions', 'minimum-stability', 'prefer-stable', 'version'];

    private const OPTIONS = ['repo', 'ref', 'remote', 'source-url', 'dist-url', 'metadata-branch', 'out-dir', 'push', 'dry-run', 'force-branches', 'no-metadata-branch', 'composer', 'version', 'help'];

    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z][0-9A-Za-z.]*)?$/';

    private PublicationGit $git;

    /** @param array<string, string|bool> $options */
    private function __construct(private string $command, private array $options)
    {
        if ($this->flag('no-metadata-branch')) {
            if (isset($options['metadata-branch']) || ! is_string($options['out-dir'] ?? null) || $options['out-dir'] === '') {
                throw new PublicationException('--no-metadata-branch requires --out-dir and cannot be combined with --metadata-branch.');
            }
            if ($command === 'metadata' && $this->flag('push')) {
                throw new PublicationException('File-only metadata must be committed and pushed on your normal branch explicitly; omit --push.');
            }
        }
        $directory = (string) ($options['repo'] ?? getcwd());
        $probe = new PublicationGit($directory);
        $this->git = new PublicationGit($probe->out(['rev-parse', '--show-toplevel']), $this->identityEnvironment($probe));
    }

    public static function main(array $argv): int
    {
        $options = [];
        $command = null;
        foreach (array_slice($argv, 1) as $argument) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $match) === 1) {
                $options[$match[1]] = $match[2] ?? true;
            } elseif ($command === null) {
                $command = $argument;
            } else {
                fwrite(STDERR, "Unexpected argument [$argument].\n");

                return 2;
            }
        }
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            fwrite(STDERR, 'Unknown option --'.implode(', --', $unknown).". Run with --help for the list.\n");

            return 2;
        }
        if ($command === null || isset($options['help'])) {
            echo "source:publish <list|split|metadata|release|verify> [--repo=DIR] [--ref=REF]\n"
                . "  release --version=X.Y.Z    create package tags and a standard Composer index\n"
                . "  --dry-run                 do not change branches/tags (Git objects may be created)\n"
                . "  --push                    explicitly push generated refs atomically\n"
                . "  --out-dir=DIR             write composer-packages.json to DIR\n"
                . "  --no-metadata-branch      only write metadata to --out-dir; do not create a metadata branch\n";

            return $command === null && ! isset($options['help']) ? 2 : 0;
        }

        try {
            $tool = new self($command, $options);

            return match ($command) {
                'list' => $tool->list(),
                'split' => $tool->split(),
                'metadata' => $tool->metadata(),
                'release' => $tool->release(),
                'verify' => $tool->verify(),
                default => throw new PublicationException("Unknown command [$command]. Use list, split, metadata, release or verify."),
            };
        } catch (PublicationException | \JsonException | \InvalidArgumentException $e) {
            fwrite(STDERR, 'source:publish: '.$e->getMessage()."\n");

            return 1;
        }
    }

    // ------------------------------------------------------------------ commands

    private function list(): int
    {
        $commit = $this->commit();
        foreach ($this->packages($commit) as $package) {
            printf("%-34s %-18s %s\n", $package->name, $package->path, $package->branch());
        }

        return 0;
    }

    private function split(): int
    {
        $commit = $this->commit();
        $packages = $this->packages($commit);
        $plan = $this->planSplit($packages, $commit);
        $this->printSplit($packages, $plan);
        if ($this->flag('dry-run')) {
            echo "Dry run: no branch was changed.\n";

            return 0;
        }
        $this->applyBranches($packages, $plan);

        return $this->flag('push') ? $this->push($this->branchRefspecs($packages)) : 0;
    }

    private function metadata(): int
    {
        $commit = $this->commit();
        $packages = $this->packages($commit);
        $document = $this->buildMetadata($packages, [], []);
        $json = $this->encode($document);
        $this->summary($document);

        if (isset($this->options['out-dir'])) {
            $this->writeOutDir($json);
        }
        if ($this->flag('dry-run')) {
            echo "Dry run: the metadata branch was not changed.\n";

            return 0;
        }
        if (! $this->flag('no-metadata-branch')) {
            $this->writeMetadataBranch($json, $document);
        }

        return $this->flag('push') ? $this->push([$this->metadataRefspec()]) : 0;
    }

    private function release(): int
    {
        $version = $this->options['version'] ?? null;
        if (! is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new PublicationException('release needs --version=MAJOR.MINOR.PATCH (a pre-release suffix such as -rc1 is allowed).');
        }

        (new \Composer\Semver\VersionParser())->normalize($version);
        $commit = $this->commit();
        $packages = $this->packages($commit);
        $plan = $this->planSplit($packages, $commit);

        // Check every tag before creating anything, so a refused release leaves no partial state.
        $newTags = [];
        $conflicts = [];
        foreach ($packages as $package) {
            $tag = $package->tag($version);
            $existing = $this->git->ref('refs/tags/'.$tag);
            if ($existing === null) {
                $newTags[$tag] = $plan[$package->name];
            } elseif ($existing !== $plan[$package->name]) {
                $conflicts[] = "$tag already exists at ".substr($existing, 0, 10).' but this ref splits to '.substr($plan[$package->name], 0, 10);
            }
        }
        if ($conflicts !== []) {
            throw new PublicationException("Version $version was already released from different content:\n  ".implode("\n  ", $conflicts)."\nPick a new version.");
        }

        // Build the metadata as it will be, with the new branches and tags laid over the repository.
        $branches = [];
        foreach ($packages as $package) {
            $branches[$package->branch()] = $plan[$package->name];
        }
        $document = $this->buildMetadata($packages, $branches, $newTags);
        $json = $this->encode($document);

        $this->printSplit($packages, $plan);
        printf("Tags to create for %s: %d (%d already present at the same commit)\n", $version, count($newTags), count($packages) - count($newTags));
        $this->summary($document);

        if (isset($this->options['out-dir'])) {
            $this->writeOutDir($json);
        }
        if ($this->flag('dry-run')) {
            echo "Dry run: no branch, tag or metadata was changed.\n";

            return 0;
        }

        $this->applyBranches($packages, $plan);
        foreach ($newTags as $tag => $sha) {
            $this->git->out(['tag', $tag, $sha]);
        }
        if (! $this->flag('no-metadata-branch')) {
            $this->writeMetadataBranch($json, $document);
        }

        if (! $this->flag('push')) {
            echo "Local only. Re-run with --push, or push the dist/ branches and the new tags yourself.\n";

            return 0;
        }

        $releaseTags = array_map(static fn (PublicationPackage $package): string => "refs/tags/{$package->tag($version)}:refs/tags/{$package->tag($version)}", $packages);

        return $this->push([...$this->branchRefspecs($packages), ...($this->flag('no-metadata-branch') ? [] : [$this->metadataRefspec()]), ...$releaseTags]);
    }

    private function verify(): int
    {
        $commit = $this->commit();
        $packages = $this->packages($commit);
        $document = $this->buildMetadata($packages, [], []);
        $this->summary($document);
        echo "Internal requirements are consistent.\n";

        if (! $this->flag('composer')) {
            return 0;
        }

        $directory = sys_get_temp_dir().'/split-packages-verify-'.bin2hex(random_bytes(4));
        mkdir($directory.'/repository', 0700, true);
        file_put_contents($directory.'/repository/composer-packages.json', $this->encode($document));
        file_put_contents($directory.'/composer.json', json_encode([
            'name' => 'split-packages/verify',
            'repositories' => [['type' => 'composer', 'url' => 'file://'.$directory.'/repository/composer-packages.json'], ['packagist.org' => false]],
            'minimum-stability' => 'dev',
        ], JSON_UNESCAPED_SLASHES));
        $environment = ['COMPOSER_HOME' => $directory.'/home', 'COMPOSER_CACHE_DIR' => $directory.'/cache', 'COMPOSER_NO_INTERACTION' => '1', 'COMPOSER_ROOT_VERSION' => '1.0.0'];
        $failed = false;
        foreach (array_keys($document['packages']) as $name) {
            $versions = array_keys($document['packages'][$name]);
            $process = proc_open(['composer', 'show', '--available', $name, '--working-dir='.$directory], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv() ?: [], $environment));
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            $code = proc_close($process);
            $seen = preg_match('/^versions\s*:\s*(.+)$/m', $output, $match) === 1 ? array_map('trim', explode(',', $match[1])) : [];
            $missing = array_diff(array_map(static fn ($v) => ltrim((string) $v, 'v'), $versions), array_map(static fn ($v) => ltrim((string) $v, '* '), $seen));
            if ($code !== 0 || $missing !== []) {
                $failed = true;
                fwrite(STDERR, "Composer cannot see $name: ".($code !== 0 ? trim($output) : 'missing '.implode(', ', $missing))."\n");
            }
        }
        if ($failed) {
            throw new PublicationException('Composer could not read the generated repository.');
        }
        echo 'Composer lists every package and version from the generated repository ('.count($document['packages'])." packages).\n";

        return 0;
    }

    // --------------------------------------------------------------- discovery

    private function commit(): string
    {
        $ref = (string) ($this->options['ref'] ?? 'HEAD');
        if ($ref === '' || str_starts_with($ref, '-')) {
            throw new PublicationException('Invalid publication ref.');
        }
        $commit = $this->git->ref($ref);
        if ($commit === null) {
            throw new PublicationException("[$ref] is not a commit in {$this->git->directory}.");
        }

        return $commit;
    }

    /** @return list<PublicationPackage> */
    private function packages(string $commit): array
    {
        $packages = PublicationDiscovery::discover($this->git, $commit);
        $metadata = $this->flag('no-metadata-branch') ? null : $this->metadataBranch();
        $checkedOut = $this->git->out(['worktree', 'list', '--porcelain']);
        foreach (array_merge($metadata === null ? [] : [$metadata], array_map(static fn (PublicationPackage $p): string => $p->branch(), $packages)) as $branch) {
            if (in_array('branch refs/heads/'.$branch, explode("\n", $checkedOut), true)) {
                throw new PublicationException('Refusing to update checked-out publication branch '.$branch.'.');
            }
        }
        foreach ($packages as $package) {
            if ($package->branch() === $metadata) {
                throw new PublicationException('Metadata and package branches must be distinct.');
            }
        }
        $parent = $metadata === null ? null : $this->git->ref('refs/heads/'.$metadata);
        if ($parent !== null) {
            $files = explode("\n", $this->git->out(['ls-tree', '--name-only', $parent]));
            sort($files);
            if ($files !== ['README.md', 'composer-packages.json'] && $files !== ['README.md', 'packages.json']) {
                throw new PublicationException('Metadata branch already contains unrelated files; refusing to replace it.');
            }
        }
        return $packages;
    }

    // ------------------------------------------------------------------- split

    /** @param list<PublicationPackage> $packages @return array<string, string> package name => split commit */
    private function planSplit(array $packages, string $commit): array
    {
        if (! is_file($this->execPath().'/git-subtree') && ! $this->git->ok(['subtree', '--help'])) {
            // `git subtree --help` also fails when only the man page is missing, so check the executable first.
            throw new PublicationException('`git subtree` is not installed.');
        }
        $plan = [];
        foreach ($packages as $package) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $this->git->out(['subtree', 'split', '--prefix='.$package->path, $commit])))));
            $sha = (string) end($lines);
            if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
                throw new PublicationException("git subtree split of {$package->path} did not return a commit (got [$sha]).");
            }
            $plan[$package->name] = $sha;
        }

        return $plan;
    }

    private function execPath(): string
    {
        return $this->git->out(['--exec-path']);
    }

    /** @param list<PublicationPackage> $packages @param array<string, string> $plan */
    private function printSplit(array $packages, array $plan): void
    {
        foreach ($packages as $package) {
            $current = $this->git->ref('refs/heads/'.$package->branch());
            $state = $current === null ? 'new' : ($current === $plan[$package->name] ? 'unchanged' : 'updated');
            printf("%-34s %-26s %s %s\n", $package->name, $package->branch(), substr($plan[$package->name], 0, 10), $state);
        }
    }

    /** @param list<PublicationPackage> $packages @param array<string, string> $plan */
    private function applyBranches(array $packages, array $plan): void
    {
        // Validate every move first: either all branches move or none does.
        foreach ($packages as $package) {
            $current = $this->git->ref('refs/heads/'.$package->branch());
            if ($current !== null && $current !== $plan[$package->name] && ! $this->git->ok(['merge-base', '--is-ancestor', $current, $plan[$package->name]]) && ! $this->flag('force-branches')) {
                throw new PublicationException("{$package->branch()} would not fast-forward (history changed); pass --force-branches if that is intended.");
            }
        }
        foreach ($packages as $package) {
            $this->git->out(['update-ref', 'refs/heads/'.$package->branch(), $plan[$package->name]]);
        }
    }

    // ---------------------------------------------------------------- metadata

    /**
     * @param list<PublicationPackage> $packages
     * @param array<string, string> $branchOverlay branch => commit not yet in the repository
     * @param array<string, string> $tagOverlay tag => commit not yet in the repository
     * @return array{packages: array<string, array<string, array<string, mixed>>>}
     */
    private function buildMetadata(array $packages, array $branchOverlay, array $tagOverlay): array
    {
        $internal = array_map(static fn (PublicationPackage $package): string => $package->name, $packages);
        $source = $this->sourceUrl();
        $document = ['packages' => []];

        foreach ($packages as $package) {
            $entries = [];

            $tip = $branchOverlay[$package->branch()] ?? $this->git->ref('refs/heads/'.$package->branch());
            if ($tip !== null) {
                $entries['dev-main'] = $this->entry($package, $tip, 'dev-main', $source, false, $internal);
            }

            $tags = [];
            foreach (array_filter(explode("\n", $this->git->out(['tag', '-l', $package->tagGlob()]))) as $tag) {
                $tags[$tag] = $this->git->ref('refs/tags/'.$tag) ?? '';
            }
            foreach ($tagOverlay as $tag => $sha) {
                if (fnmatch($package->tagGlob(), $tag)) {
                    $tags[$tag] = $sha;
                }
            }
            foreach ($tags as $tag => $sha) {
                $version = substr($tag, strlen($package->short()) + 2);
                if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
                    continue; // not a release tag
                }
                $entries[$version] = $this->entry($package, $sha, $version, $source, true, $internal);
            }
            if ($entries === []) {
                continue;
            }
            uksort($entries, static function (string $a, string $b): int {
                if ($a === 'dev-main' || $b === 'dev-main') {
                    return $a === 'dev-main' ? -1 : 1;
                }

                return version_compare($b, $a);
            });
            $document['packages'][$package->name] = $entries;
        }
        if ($document['packages'] === []) {
            throw new PublicationException('No dist/<package> branches or release tags exist yet; run `split` or `release` first.');
        }
        ksort($document['packages'], SORT_STRING);
        $this->assertConsistent($document, $internal);

        return $document;
    }

    /**
     * @param list<string> $internal
     * @return array<string, mixed>
     */
    private function entry(PublicationPackage $package, string $sha, string $version, string $source, bool $tagged, array $internal): array
    {
        $show = $this->git->run(['show', "$sha:composer.json"]);
        $manifest = $show['code'] === 0 ? json_decode($show['out'], true) : null;
        if (! is_array($manifest) || ($manifest['name'] ?? null) !== $package->name) {
            throw new PublicationException("{$package->branch()} at ".substr($sha, 0, 10).' has no composer.json for '.$package->name.'.');
        }
        PublicationManifest::validate($manifest);
        if ($tagged && isset($manifest['version'])) {
            $parser = new \Composer\Semver\VersionParser();
            if ($parser->normalize((string) $manifest['version']) !== $parser->normalize($version)) {
                throw new PublicationException('Manifest version disagrees with release '.$version.' for '.$package->name.'. Update or remove the explicit version in a new source commit.');
            }
        }
        $entry = array_diff_key($manifest, array_flip(self::ROOT_ONLY_KEYS));

        // A tagged release must be installable as a stable set: `dev-main` between packages becomes ^version.
        if ($tagged && isset($entry['require']) && is_array($entry['require'])) {
            foreach ($entry['require'] as $dependency => $constraint) {
                if (in_array($dependency, $internal, true) && in_array($constraint, ['dev-main', '@dev'], true)) {
                    $entry['require'][$dependency] = '^'.$version;
                }
            }
        }

        $entry['version'] = $version;
        $entry['time'] = $this->git->out(['log', '-1', '--format=%cI', $sha]);
        $entry['source'] = ['type' => 'git', 'url' => $source, 'reference' => $sha];
        $dist = $this->distUrl($source, $sha);
        if ($dist !== null) {
            $entry['dist'] = ['type' => 'zip', 'url' => $dist, 'reference' => $sha, 'shasum' => ''];
        }

        return $entry;
    }

    /**
     * Every internal ^X requirement of a tagged version must be satisfiable by a published version
     * of that package; a release that tags only some packages is refused rather than shipped.
     *
     * @param array{packages: array<string, array<string, array<string, mixed>>>} $document
     * @param list<string> $internal
     */
    private function assertConsistent(array $document, array $internal): void
    {
        $problems = [];
        foreach ($document['packages'] as $name => $versions) {
            foreach ($versions as $version => $entry) {
                foreach ((array) ($entry['require'] ?? []) as $dependency => $constraint) {
                    if (! in_array($dependency, $internal, true)) {
                        continue;
                    }
                    $versions = array_keys($document['packages'][$dependency] ?? []);
                    if (\Composer\Semver\Semver::satisfiedBy($versions, (string) $constraint) === []) {
                        $problems[] = "$name $version requires $dependency $constraint, but no published version satisfies it";
                    }
                }
            }
        }
        if ($problems !== []) {
            throw new PublicationException("The metadata would not be installable:\n  ".implode("\n  ", array_unique($problems)));
        }
    }

    /** @param array{packages: array<string, array<string, array<string, mixed>>>} $document */
    private function encode(array $document): string
    {
        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @param array{packages: array<string, array<string, array<string, mixed>>>} $document */
    private function summary(array $document): void
    {
        $versions = array_sum(array_map('count', $document['packages']));
        printf("Metadata: %d packages, %d versions (source %s)\n", count($document['packages']), $versions, $this->sourceUrl());
    }

    private function writeOutDir(string $json): void
    {
        $directory = (string) $this->options['out-dir'];
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new PublicationException("Cannot create $directory.");
        }
        file_put_contents($directory.'/composer-packages.json', $json);
        echo "Wrote $directory/composer-packages.json\n";
    }

    /** @param array{packages: array<string, array<string, array<string, mixed>>>} $document */
    private function writeMetadataBranch(string $json, array $document): void
    {
        $branch = $this->metadataBranch();
        $readme = "# Composer repository for the workspace packages\n\n"
            ."Generated by `composer source:publish`; do not edit. `composer-packages.json` lists every workspace package and version published from the monorepo.\n\n"
            ."Add it to a project's `composer.json`:\n\n```json\n{ \"repositories\": [ { \"type\": \"composer\", \"url\": \"".$this->repositoryUrlHint($branch)."\" } ] }\n```\n";
        $tree = $this->git->out(['mktree'], sprintf(
            "100644 blob %s\tREADME.md\n100644 blob %s\tcomposer-packages.json\n",
            $this->git->out(['hash-object', '-w', '--stdin'], $readme),
            $this->git->out(['hash-object', '-w', '--stdin'], $json),
        ));

        $parent = $this->git->ref('refs/heads/'.$branch);
        if ($parent !== null && $this->git->out(['rev-parse', $parent.'^{tree}']) === $tree) {
            echo "Metadata branch $branch is already up to date.\n";

            return;
        }
        $message = sprintf('Update the Composer package metadata (%d packages, %d versions)', count($document['packages']), array_sum(array_map('count', $document['packages'])));
        $commit = $this->git->out(array_merge(['commit-tree', $tree], $parent === null ? [] : ['-p', $parent], ['-m', $message]));
        $this->git->out(['update-ref', 'refs/heads/'.$branch, $commit]);
        echo "Metadata branch $branch updated (".substr($commit, 0, 10).").\n";
    }

    // -------------------------------------------------------------------- push

    /** @param list<PublicationPackage> $packages @return list<string> */
    private function branchRefspecs(array $packages): array
    {
        return array_map(static fn (PublicationPackage $package): string => "refs/heads/{$package->branch()}:refs/heads/{$package->branch()}", $packages);
    }

    private function metadataRefspec(): string
    {
        $branch = $this->metadataBranch();

        return "refs/heads/$branch:refs/heads/$branch";
    }

    /** @param list<string> $refspecs */
    private function push(array $refspecs): int
    {
        $remote = $this->remote();
        if ($this->flag('dry-run')) {
            echo "Dry run: would push ".count($refspecs)." refs to $remote.\n";

            return 0;
        }
        $result = $this->git->run(['push', '--atomic', $remote, ...$refspecs]);
        if ($result['code'] !== 0) {
            throw new PublicationException("git push to $remote failed (nothing was pushed): ".trim($result['err']));
        }
        echo 'Pushed '.count($refspecs)." refs to $remote.\n";

        return 0;
    }

    // ----------------------------------------------------------------- helpers

    private function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) === true;
    }

    private function metadataBranch(): string
    {
        $branch = (string) ($this->options['metadata-branch'] ?? 'dist/composer');
        if (! $this->git->ok(['check-ref-format', 'refs/heads/'.$branch])) {
            throw new PublicationException('Invalid metadata branch: '.$branch);
        }
        return $branch;
    }

    private function remote(): string
    {
        if (isset($this->options['remote'])) {
            return (string) $this->options['remote'];
        }
        $remotes = array_filter(explode("\n", $this->git->out(['remote'])));
        foreach (['upstream', 'origin'] as $candidate) {
            if (in_array($candidate, $remotes, true)) {
                return $candidate;
            }
        }
        throw new PublicationException('No remote found; pass --remote or --source-url.');
    }

    private function sourceUrl(): string
    {
        if (isset($this->options['source-url'])) {
            return (string) $this->options['source-url'];
        }
        $url = $this->git->out(['remote', 'get-url', $this->remote()]);
        if (preg_match('#^(?:git@|ssh://git@)([^:/]+)[:/](.+?)(?:\.git)?$#', $url, $match) === 1) {
            return "https://{$match[1]}/{$match[2]}.git";
        }

        return $url;
    }

    private function distUrl(string $source, string $sha): ?string
    {
        $template = $this->options['dist-url'] ?? null;
        if (is_string($template)) {
            return str_replace('{sha}', $sha, $template);
        }
        if (preg_match('#^https://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $source, $match) === 1) {
            return "https://api.github.com/repos/{$match[1]}/{$match[2]}/zipball/$sha";
        }

        return null;
    }

    private function repositoryUrlHint(string $branch): string
    {
        if (preg_match('#^https://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $this->sourceUrl(), $match) === 1) {
            return "https://raw.githubusercontent.com/{$match[1]}/{$match[2]}/$branch/composer-packages.json";
        }

        return 'URL of composer-packages.json';
    }

    /** @return array<string, string> author identity for the metadata commit when git has none (CI) */
    private function identityEnvironment(PublicationGit $git): array
    {
        $environment = [];
        if (trim($git->run(['config', 'user.name'])['out']) === '') {
            $environment['GIT_AUTHOR_NAME'] = $environment['GIT_COMMITTER_NAME'] = 'Composer Source Publisher';
        }
        if (trim($git->run(['config', 'user.email'])['out']) === '') {
            $environment['GIT_AUTHOR_EMAIL'] = $environment['GIT_COMMITTER_EMAIL'] = 'noreply@users.noreply.github.com';
        }

        return $environment;
    }
}
