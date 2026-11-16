<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

final class PublicationGit
{
    /** @param array<string, string> $env */
    public function __construct(public readonly string $directory, private array $env = [])
    {
    }

    /** @param list<string> $arguments @return array{code: int, out: string, err: string} */
    public function run(array $arguments, ?string $stdin = null): array
    {
        // Subtree progress can exceed a pipe buffer before stdout is emitted.
        // Spool stderr so reading stdout cannot deadlock against Git's progress.
        $error = tmpfile();
        if ($error === false) {
            throw new PublicationException('Cannot prepare Git diagnostics.');
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $error];
        $process = proc_open(['git', ...$arguments], $descriptors, $pipes, $this->directory, $this->env === [] ? null : array_merge(getenv() ?: [], $this->env));
        if (! is_resource($process)) {
            fclose($error);
            throw new PublicationException('Unable to run git.');
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        rewind($error);
        $err = stream_get_contents($error);
        fclose($error);
        return ['code' => $code, 'out' => (string) $out, 'err' => (string) $err];
    }

    /** @param list<string> $arguments */
    public function out(array $arguments, ?string $stdin = null): string
    {
        $result = $this->run($arguments, $stdin);
        if ($result['code'] !== 0) {
            throw new PublicationException('git '.implode(' ', $arguments).' failed: '.trim($result['err'] ?: $result['out']));
        }

        return rtrim($result['out'], "\n");
    }

    /** @param list<string> $arguments */
    public function ok(array $arguments): bool
    {
        return $this->run($arguments)['code'] === 0;
    }

    public function ref(string $name): ?string
    {
        $result = $this->run(['rev-parse', '--verify', '-q', $name.'^{commit}']);

        return $result['code'] === 0 ? trim($result['out']) : null;
    }
}
