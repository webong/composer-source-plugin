<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webong\ComposerSource\MirrorState;
use Webong\ComposerSource\ProjectionState;
use Webong\ComposerSource\SourceLock;

final class ProjectionStateTest extends TestCase
{
    private string $lock = '';

    protected function setUp(): void
    {
        $this->lock = sys_get_temp_dir() . '/source-lock-' . bin2hex(random_bytes(6)) . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->lock);
    }

    public function testItRecordsAndReadsAProjectionEntry(): void
    {
        ProjectionState::save($this->lock, 'acme/monolith', $this->entry(['core', 'javascript']));

        $entry = ProjectionState::entry($this->lock, 'acme/monolith');

        self::assertNotNull($entry);
        self::assertSame(['core', 'javascript'], $entry['surfaces']);
        self::assertSame(ProjectionState::FORMAT, $entry['format']);
    }

    public function testItPreservesOtherProjectedPackages(): void
    {
        ProjectionState::save($this->lock, 'acme/monolith', $this->entry(['core']));
        ProjectionState::save($this->lock, 'acme/other', $this->entry(['javascript']));

        self::assertNotNull(ProjectionState::entry($this->lock, 'acme/monolith'));
        self::assertNotNull(ProjectionState::entry($this->lock, 'acme/other'));
    }

    public function testItPreservesMirrorState(): void
    {
        MirrorState::save(sys_get_temp_dir(), 'acme/fluent', 'ref', ['a.php' => str_repeat('a', 64)], $this->lock);
        ProjectionState::save($this->lock, 'acme/monolith', $this->entry(['core']));

        $lock = SourceLock::read($this->lock);

        self::assertArrayHasKey('acme/fluent', $lock['mirrors']);
        self::assertArrayHasKey('acme/monolith', $lock['projections']);
    }

    public function testMirrorStateWritesPreserveProjectionState(): void
    {
        ProjectionState::save($this->lock, 'acme/monolith', $this->entry(['core']));
        MirrorState::save(sys_get_temp_dir(), 'acme/fluent', 'ref', ['a.php' => str_repeat('a', 64)], $this->lock);

        $lock = SourceLock::read($this->lock);

        self::assertArrayHasKey('acme/fluent', $lock['mirrors']);
        self::assertArrayHasKey('acme/monolith', $lock['projections']);
    }

    public function testItRejectsAnUnsupportedFormat(): void
    {
        file_put_contents($this->lock, json_encode(['version' => 1, 'mirrors' => [], 'projections' => [
            'acme/monolith' => ['format' => 99],
        ]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid projection state');

        ProjectionState::entry($this->lock, 'acme/monolith');
    }

    public function testItRejectsAbsolutePathsOrDigestDriftInState(): void
    {
        $entry = $this->entry(['core']);
        $entry['patterns'] = ['/tmp/secret'];

        file_put_contents($this->lock, json_encode(['version' => 1, 'mirrors' => [], 'projections' => [
            'acme/monolith' => $entry,
        ]]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid projection state');

        ProjectionState::entry($this->lock, 'acme/monolith');
    }

    /** @param list<string> $surfaces @return array<string, mixed> */
    private function entry(array $surfaces): array
    {
        return [
            'format' => ProjectionState::FORMAT,
            'reference' => 'ref',
            'version' => '1.0.0',
            'surfaces' => $surfaces,
            'patterns' => ['composer.json', 'src/Core/**'],
            'patterns_digest' => str_repeat('a', 64),
            'definition_digest' => str_repeat('b', 64),
        ];
    }
}
