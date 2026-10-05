<?php

namespace SextaNet\LaravelDatabaseCopy;

use SextaNet\LaravelDatabaseCopy\Commands\ExportCommand;
use SextaNet\LaravelDatabaseCopy\Commands\ImportCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelDatabaseCopyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('database-copy')
            ->hasConfigFile()
            ->hasCommands([
                ExportCommand::class,
                ImportCommand::class,
            ]);
    }
}
