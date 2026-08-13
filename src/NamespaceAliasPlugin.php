<?php

declare(strict_types=1);

namespace Webong\ComposerNamespaceAlias;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\EventSubscriberInterface;

final class NamespaceAliasPlugin implements PluginInterface, EventSubscriberInterface
{
    private ?Composer $composer = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $this->composer = null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'generateAliases',
        ];
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
