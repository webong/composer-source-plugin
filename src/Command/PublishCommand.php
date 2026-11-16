<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Command;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webong\ComposerSource\PackagePublisher;

final class PublishCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('source:publish')
            ->setDescription('Split committed workspaces and generate a standard Composer repository.')
            ->addArgument('action', InputArgument::OPTIONAL, 'list, split, metadata, release or verify', 'list');
        foreach (['repo', 'ref', 'remote', 'source-url', 'dist-url', 'metadata-branch', 'out-dir', 'release-version'] as $option) {
            $this->addOption($option, null, InputOption::VALUE_REQUIRED, 'Publication '.$option.'.');
        }
        foreach (['push', 'dry-run', 'force-branches', 'no-metadata-branch', 'composer'] as $option) {
            $this->addOption($option, null, InputOption::VALUE_NONE, 'Enable '.$option.'.');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arguments = ['source:publish', (string) $input->getArgument('action')];
        foreach (['repo', 'ref', 'remote', 'source-url', 'dist-url', 'metadata-branch', 'out-dir', 'release-version'] as $option) {
            if ($input->getOption($option) !== null) {
                $arguments[] = '--'.($option === 'release-version' ? 'version' : $option).'='.$input->getOption($option);
            }
        }
        foreach (['push', 'dry-run', 'force-branches', 'no-metadata-branch', 'composer'] as $option) {
            if ($input->getOption($option)) {
                $arguments[] = '--'.$option;
            }
        }
        return PackagePublisher::main($arguments);
    }
}
