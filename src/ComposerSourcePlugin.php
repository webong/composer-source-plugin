<?php

declare(strict_types=1);

namespace Webong\ComposerSource;

use Composer\Composer;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\EventSubscriberInterface;

final class ComposerSourcePlugin implements PluginInterface, EventSubscriberInterface
{
    private ?Composer $composer = null;

    private ?IOInterface $io = null;

    private bool $updating = false;

    private bool $reinstalling = false;

    /** @var array<string, true> */
    private array $freshPackages = [];

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
            PluginEvents::COMMAND => 'beginCommand',
            ScriptEvents::PRE_UPDATE_CMD => 'beginUpdate',
            'pre-pool-create' => 'selectPackageSources',
            PackageEvents::POST_PACKAGE_INSTALL => 'projectPackage',
            PackageEvents::POST_PACKAGE_UPDATE => 'projectPackage',
            // Projections are applied during package installation, before the
            // autoloader is generated. The pre-dump pass reconciles projected
            // packages that were not reinstalled (notably narrowing).
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'beforeAutoloadDump',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'generateAliases',
            // Covers operations that skip autoload generation; a no-install
            // update never records package events, so this stays inert there.
            ScriptEvents::POST_INSTALL_CMD => 'finalizeProjections',
            ScriptEvents::POST_UPDATE_CMD => 'finalizeProjections',
        ];
    }

    public function beginCommand(object $event): void
    {
        if (method_exists($event, 'getCommandName')) {
            $this->reinstalling = $event->getCommandName() === 'reinstall';
        }
        $this->freshPackages = [];
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

    public function projectPackage(PackageEvent $event): void
    {
        if (! $this->composer instanceof Composer || ! $this->io instanceof IOInterface) {
            return;
        }

        $operation = $event->getOperation();
        $package = method_exists($operation, 'getTargetPackage')
            ? $operation->getTargetPackage()
            : $operation->getPackage();

        $name = strtolower($package->getName());
        $this->freshPackages[$name] = true;

        $this->manager()->projectPackage($package);
    }

    public function beforeAutoloadDump(Event $event): void
    {
        if (! $this->composer instanceof Composer) {
            return;
        }

        $this->manager()->reconcile($this->freshPackages);
        $this->syncMirrors($event);
    }

    public function finalizeProjections(Event $event): void
    {
        if (! $this->composer instanceof Composer || $this->freshPackages === []) {
            return;
        }

        $this->manager()->reconcile($this->freshPackages);
    }

    public function syncMirrors(Event $event): void
    {
        if (! $this->composer instanceof Composer) {
            return;
        }

        (new PackageMirrorSynchroniser($this->composer, $event->getIO(), $this->updating))->sync();
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

    private function manager(): ProjectionManager
    {
        return new ProjectionManager(
            $this->composer,
            $this->io,
            $this->updating,
            $this->reinstalling,
        );
    }
}
