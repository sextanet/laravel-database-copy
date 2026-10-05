<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');

    Schema::create('local_table', fn ($table) => $table->id());
});

it('replaces this database with the latest copy from production', function () {
    storeCopy('production/my-app/2026-10-03-03-30-00.zip', ['users-1@anonymized.test']);
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['users-2@anonymized.test']);

    $this->artisan('database-copy:import', ['--force' => true])
        ->expectsOutputToContain('production/my-app/2026-10-04-03-30-00.zip')
        ->assertSuccessful();

    expect(DB::table('users')->sole()->email)->toBe('users-2@anonymized.test')
        ->and(Schema::hasTable('local_table'))->toBeFalse();
});

it('runs the migrations of this environment after importing', function () {
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['users-1@anonymized.test']);

    $this->artisan('database-copy:import', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('migrations'))->toBeTrue();
});

it('imports a specific copy', function () {
    storeCopy('production/my-app/2026-10-03-03-30-00.zip', ['users-1@anonymized.test']);
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['users-2@anonymized.test']);

    $this->artisan('database-copy:import', ['--force' => true, '--file' => 'production/my-app/2026-10-03-03-30-00.zip'])->assertSuccessful();

    expect(DB::table('users')->sole()->email)->toBe('users-1@anonymized.test');
});

it('imports from another environment', function () {
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['users-1@anonymized.test']);
    storeCopy('staging/my-app/2026-10-01-03-30-00.zip', ['users-2@anonymized.test']);

    $this->artisan('database-copy:import', ['--force' => true, '--from' => 'staging'])->assertSuccessful();

    expect(DB::table('users')->sole()->email)->toBe('users-2@anonymized.test');
});

it('refuses a copy with real data', function () {
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['maria@gmail.com'], anonymized: false);

    $this->artisan('database-copy:import', ['--force' => true])
        ->expectsOutputToContain('--with-real-data')
        ->assertFailed();

    expect(Schema::hasTable('local_table'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeFalse();
});

it('imports a copy with real data when allowed', function () {
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['maria@gmail.com'], anonymized: false);

    $this->artisan('database-copy:import', ['--force' => true, '--with-real-data' => true])->assertSuccessful();

    expect(DB::table('users')->sole()->email)->toBe('maria@gmail.com');
});

it('never runs in a protected environment', function () {
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['users-1@anonymized.test']);
    $this->app['env'] = 'production';

    $this->artisan('database-copy:import', ['--force' => true])
        ->expectsOutputToContain('does not run in production')
        ->assertFailed();

    expect(Schema::hasTable('local_table'))->toBeTrue();
});

it('asks for confirmation', function () {
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['users-1@anonymized.test']);

    $this->artisan('database-copy:import')
        ->expectsConfirmation('The whole database ('.DB::getDatabaseName().') will be replaced by production/my-app/2026-10-04-03-30-00.zip. Continue?', 'no')
        ->assertFailed();

    expect(Schema::hasTable('local_table'))->toBeTrue();
});

it('fails when there are no copies', function () {
    $this->artisan('database-copy:import', ['--force' => true])
        ->expectsOutputToContain('No copies found in production/my-app')
        ->assertFailed();
});

it('fails when the copy does not exist', function () {
    $this->artisan('database-copy:import', ['--force' => true, '--file' => 'production/my-app/missing.zip'])
        ->expectsOutputToContain('The copy production/my-app/missing.zip could not be downloaded')
        ->assertFailed();

    expect(Schema::hasTable('local_table'))->toBeTrue()
        ->and(File::isEmptyDirectory($this->directory.'/temporary'))->toBeTrue();
});

it('fails when the password is different', function () {
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['users-1@anonymized.test']);
    config()->set('database-copy.password', 'another-password');

    $this->artisan('database-copy:import', ['--force' => true])
        ->expectsOutputToContain('The copy production/my-app/2026-10-04-03-30-00.zip can not be decrypted')
        ->assertFailed();

    expect(Schema::hasTable('local_table'))->toBeTrue();
});

it('cleans up the temporary directory', function () {
    storeCopy('production/my-app/2026-10-04-03-30-00.zip', ['users-1@anonymized.test']);

    $this->artisan('database-copy:import', ['--force' => true])->assertSuccessful();

    expect(File::isEmptyDirectory($this->directory.'/temporary'))->toBeTrue();
});
