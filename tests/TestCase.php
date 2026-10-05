<?php

namespace SextaNet\LaravelDatabaseCopy\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use SextaNet\LaravelDatabaseCopy\LaravelDatabaseCopyServiceProvider;

class TestCase extends Orchestra
{
    public string $directory;

    protected function getPackageProviders($app)
    {
        return [
            LaravelDatabaseCopyServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $this->directory = sys_get_temp_dir().'/laravel-database-copy-tests/'.uniqid();

        File::ensureDirectoryExists($this->directory);
        touch("{$this->directory}/database.sqlite");

        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => "{$this->directory}/database.sqlite",
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        config()->set('database-copy.name', 'my-app');
        config()->set('database-copy.disk', 'backups');
        config()->set('database-copy.password', 'secret-password');
        config()->set('database-copy.temporary_directory', "{$this->directory}/temporary");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }
}
