<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\EventSubscriberInterface;

final class ComposerSourcePlugin implements PluginInterface, EventSubscriberInterface
{
    private ?Composer $composer = null;

    private ?IOInterface $io = null;

    private bool $updating = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        // Fail loudly and early on a contradictory configuration, before any
        // command mutates the dependency pool.
        SourcePluginConfig::assertValid($composer);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
        $this->io = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
        $this->io = null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_UPDATE_CMD => 'beginUpdate',
            'pre-pool-create' => 'selectPackageSources',
            // Sync runs immediately before the autoload dump so the rebase
            // always reads post-sync sources. post-install-cmd and
            // post-update-cmd both fire after the dump, which is too late.
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'syncMirrors',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'generateAliases',
        ];
    }

    public function syncMirrors(Event $event): void
    {
        if (! $this->composer instanceof Composer) {
            return;
        }

        (new PackageMirrorSynchroniser($this->composer, $event->getIO(), $this->updating))->sync();
    }

    public function beginUpdate(Event $event): void
    {
        $this->updating = true;
    }

    /**
     * Select one configured package source before Composer resolves the pool.
     *
     * The event is intentionally untyped so the plugin remains installable
     * with Composer plugin API 2.0; pre-pool-create is available in newer
     * Composer 2 releases and is ignored when unavailable.
     */
    public function selectPackageSources(object $event): void
    {
        if (! $this->composer instanceof Composer || ! $this->io instanceof IOInterface || ! method_exists($event, 'getPackages')) {
            return;
        }

        (new PackageSourceSelector($this->composer, $this->io))->select($event);
    }

    public function generateAliases(Event $event): void
    {
        if (! $this->composer instanceof Composer) {
            return;
        }

        $generator = new NamespaceAliasGenerator(
            $this->composer,
            $event->getIO(),
        );

        $generator->generate();
    }
}
