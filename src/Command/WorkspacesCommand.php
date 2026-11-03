<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Command;

use Composer\Command\BaseCommand;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webong\ComposerSource\SourcePluginConfig;
use Webong\ComposerSource\WorkspaceConfiguration;
use Webong\ComposerSource\WorkspaceDefinition;

/**
 * `composer source:workspaces` — show the resolved workspace set.
 *
 * Activation defers configuration errors to the command phase, so this command
 * can still run and report every problem when normal commands refuse to start.
 */
final class WorkspacesCommand extends BaseCommand
{
    public const NAME = 'source:workspaces';

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Show the source-plugin workspaces and how each package resolves.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();

        try {
            $workspaces = WorkspaceConfiguration::discover($composer);
            $loaders = SourcePluginConfig::loaders($composer);
            $active = WorkspaceConfiguration::active($workspaces, $loaders);

            $problems = [];
            try {
                SourcePluginConfig::assertValid($composer, $workspaces);
            } catch (InvalidArgumentException $exception) {
                $problems[] = $exception->getMessage();
            }

            $problems = array_merge($problems, WorkspaceConfiguration::problems(
                WorkspaceConfiguration::composerRequires($composer),
                $active,
            ));
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return 1;
        }

        if ($input->getOption('json')) {
            $output->writeln($this->json($workspaces, $active, $problems));
        } else {
            $this->report($output, $workspaces, $active, $problems);
        }

        return $problems === [] ? 0 : 1;
    }

    /**
     * @param array<string, WorkspaceDefinition> $workspaces
     * @param array<string, WorkspaceDefinition> $active
     * @param list<string> $problems
     */
    private function report(
        OutputInterface $output,
        array $workspaces,
        array $active,
        array $problems,
    ): void {
        if ($workspaces === []) {
            $output->writeln('No workspaces are configured under extra.source-plugin.workspaces.');

            return;
        }

        $output->writeln(sprintf(
            '<info>%d workspace(s): %d linked, %d outline.</info>',
            count($workspaces),
            count($active),
            count($workspaces) - count($active),
        ));

        foreach ($workspaces as $package => $workspace) {
            $output->writeln(sprintf(
                '  %s %s %s %s',
                str_pad($package, 24),
                str_pad($workspace->path, 30),
                str_pad($workspace->version ?? 'dev', 10),
                isset($active[$package]) ? 'linked' : 'outline',
            ));

            foreach ($this->workspaceRequires($workspace, $workspaces) as $target => $constraint) {
                $output->writeln(sprintf('      requires %s %s', $target, $constraint));
            }
        }

        foreach ($problems as $problem) {
            $output->writeln('<error>' . $problem . '</error>');
        }
    }

    /**
     * @param array<string, WorkspaceDefinition> $workspaces
     * @param array<string, WorkspaceDefinition> $active
     * @param list<string> $problems
     */
    private function json(array $workspaces, array $active, array $problems): string
    {
        $packages = [];
        foreach ($workspaces as $package => $workspace) {
            $packages[] = [
                'package' => $package,
                'path' => $workspace->path,
                'version' => $workspace->version,
                'mode' => isset($active[$package]) ? 'linked' : 'outline',
                'symlink' => $workspace->symlink,
                'requires' => $this->workspaceRequires($workspace, $workspaces),
            ];
        }

        return (string) json_encode(
            ['workspaces' => $packages, 'problems' => $problems],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param array<string, WorkspaceDefinition> $workspaces
     * @return array<string, string>
     */
    private function workspaceRequires(WorkspaceDefinition $workspace, array $workspaces): array
    {
        $requires = [];
        foreach ($workspace->requires as $package => $constraint) {
            if (isset($workspaces[$package])) {
                $requires[$package] = $constraint;
            }
        }

        return $requires;
    }
}
