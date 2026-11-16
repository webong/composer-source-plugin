<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Command;

use Composer\Command\BaseCommand;
use Composer\Config\JsonConfigSource;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\RepositorySet;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webong\ComposerSource\CatalogDefinition;

/** Discover a standard index through a package's Packagist metadata. */
final class CatalogCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('source:catalog')
            ->setDescription('Register a Composer repository advertised by a Packagist catalog package.')
            ->addArgument('package', InputArgument::REQUIRED, 'Catalog package, optionally followed by :constraint.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the repository without changing composer.json.')
            ->addOption('allow-local', null, InputOption::VALUE_NONE, 'Allow file:// catalogs for local testing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        [$name, $constraint] = array_pad(explode(':', (string) $input->getArgument('package'), 2), 2, '*');
        if (preg_match('{^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:[_.-]?[a-z0-9]+)*$}D', $name) !== 1 || $constraint === '') {
            throw new InvalidArgumentException('Use vendor/catalog[:constraint].');
        }
        // Use Composer's repository priority and stability rules, not ad-hoc HTTP.
        $set = new RepositorySet();
        foreach ($composer->getRepositoryManager()->getRepositories() as $repository) {
            $set->addRepository($repository);
        }
        $package = (new VersionSelector($set))->findBestCandidate($name, $constraint);
        if ($package === false) {
            throw new RuntimeException('No stable catalog matches '.$name.':'.$constraint.'.');
        }
        $extra = $package->getExtra();
        $catalog = CatalogDefinition::parse($extra['source-plugin']['catalog'] ?? null, (bool) $input->getOption('allow-local'));
        $config = $catalog->repository();
        $key = 'source-'.str_replace('/', '--', $name);
        $output->writeln($name.' '.$package->getPrettyVersion().' advertises:');
        $output->writeln(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($input->getOption('dry-run')) {
            return 0;
        }
        $file = new JsonFile(Factory::getComposerFile());
        $root = $file->read();
        foreach ($root['repositories'] ?? [] as $repositoryKey => $existing) {
            if ($repositoryKey !== $key && (! is_array($existing) || ($existing['name'] ?? null) !== $key)) {
                continue;
            }
            if (is_array($existing)) {
                unset($existing['name']);
            }
            if ($existing !== $config) {
                throw new RuntimeException('Repository '.$key.' already has different settings; review and remove it explicitly before changing its source.');
            }
            $output->writeln('Catalog is already registered.');
            return 0;
        }
        // This is an explicit setup command. Normal install/update never rewrites manifests.
        (new JsonConfigSource($file))->addRepository($key, $config, false);
        $output->writeln('Registered '.$key.'. Use composer require with the advertised package names.');
        return 0;
    }
}
