<?php

declare(strict_types=1);

namespace Webong\ComposerSource\Illuminate;

use Illuminate\Support\ServiceProvider;

final class ComposerSourceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $aliasesPath = $this->app->basePath('vendor/composer/source_aliases.php');

        if (! is_file($aliasesPath)) {
            return;
        }

        $aliases = require $aliasesPath;

        if (! is_array($aliases)) {
            return;
        }

        foreach ($aliases as $source => $alias) {
            if (is_string($source) && is_string($alias) && $source !== '' && $alias !== '') {
                $this->app->alias($source, $alias);
            }
        }
    }
}
