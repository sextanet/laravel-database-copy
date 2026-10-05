<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SextaNet\LaravelDatabaseCopy\Archive;
use SextaNet\LaravelDatabaseCopy\Tests\TestCase;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Wnx\LaravelBackupRestore\DbImporterFactory;

uses(TestCase::class)->in(__DIR__);

function createUsersTable(?string $connection = null): void
{
    Schema::connection($connection)->create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('rut')->nullable();
        $table->string('phone')->nullable();
        $table->date('birthday')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });
}

function createSessionsTable(?string $connection = null): void
{
    Schema::connection($connection)->create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->foreignId('user_id')->nullable();
        $table->longText('payload');
        $table->integer('last_activity');
    });
}

/**
 * Download a copy from the disk and import it into the "copy" connection, returning its manifest.
 *
 * @return array<string, mixed>
 */
function openCopy(string $path): array
{
    $directory = test()->directory.'/opened';

    File::ensureDirectoryExists($directory);
    File::put("{$directory}/copy.zip", Storage::disk('backups')->get($path));
    touch("{$directory}/copy.sqlite");

    $manifest = app(Archive::class)->extract("{$directory}/copy.zip", $directory);

    config()->set('database.connections.copy', array_merge(config('database.connections.testing'), ['database' => "{$directory}/copy.sqlite"]));
    DbImporterFactory::createFromConnection('copy')->importToDatabase("{$directory}/".Archive::DUMP, 'copy');

    return $manifest;
}

/**
 * Store a copy on the disk with the given users, as database-copy:export would.
 *
 * @param  list<string>  $emails
 */
function storeCopy(string $path, array $emails, bool $anonymized = true): void
{
    $directory = test()->directory.'/'.uniqid('fixture-');

    File::ensureDirectoryExists($directory);
    touch("{$directory}/database.sqlite");

    config()->set('database.connections.fixture', array_merge(config('database.connections.testing'), ['database' => "{$directory}/database.sqlite"]));
    createUsersTable('fixture');

    foreach ($emails as $email) {
        DB::connection('fixture')->table('users')->insert(['name' => 'User', 'email' => $email, 'password' => 'secret']);
    }

    DbDumperFactory::createFromConnection('fixture')->dumpToFile("{$directory}/dump.sql");
    DB::purge('fixture');

    $zip = app(Archive::class)->create("{$directory}/dump.sql", ['environment' => 'production', 'anonymized' => $anonymized], "{$directory}/copy.zip");

    Storage::disk('backups')->put($path, File::get($zip));
}
