<?php

declare(strict_types=1);

use Composer\InstalledVersions;

/*
 * Registered as a "files" autoload entry of this plugin package.
 *
 * Composer only emits the files-loading section of the generated autoloader
 * when at least one installed package declares a "files" autoload entry, so a
 * plugin cannot rely on there being an autoload_files.php to patch. Declaring
 * the entry here means Composer always generates the loader section, and this
 * file is always executed, which is the only reliable hook for the generated
 * alias and rebase files.
 *
 * Composer registers the class loader before requiring files entries, so
 * InstalledVersions is available here.
 */

$vendorDir = null;

try {
    $installPath = InstalledVersions::getInstallPath('webong/composer-source-plugin');
    if (is_string($installPath) && $installPath !== '') {
        // <vendor>/composer/../<vendor>/<name> or <vendor>/<vendor>/<name>
        $vendorDir = dirname(rtrim($installPath, '/\\'), 2);
    }
} catch (Throwable) {
    $vendorDir = null;
}

if ($vendorDir === null || ! is_dir($vendorDir)) {
    // Fall back to walking up from this file. __DIR__ is resolved through
    // symlinks, so this only holds for a standard install.
    $vendorDir = __DIR__;
    for ($depth = 0; $depth < 6; $depth++) {
        if (is_file($vendorDir . '/composer/ClassLoader.php')) {
            break;
        }

        $vendorDir = dirname($vendorDir);
    }
}

foreach (['namespace_aliases.php', 'namespace_rebases.php'] as $generated) {
    $file = $vendorDir . '/composer/' . $generated;

    if (is_file($file)) {
        require_once $file;
    }
}
