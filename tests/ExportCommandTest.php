<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('backups');
    $this->travelTo('2026-10-04 03:30:00');

    createUsersTable();
    createSessionsTable();

    DB::table('users')->insert(['name' => 'María González', 'email' => 'maria@gmail.com', 'password' => 'secret']);
    DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'secret', 'last_activity' => 1]);
});

it('uploads an anonymized copy to the folder of this environment', function () {
    $this->artisan('database-copy:export')
        ->expectsOutputToContain('testing/my-app/2026-10-04-03-30-00.zip')
        ->assertSuccessful();

    expect(Storage::disk('backups')->files('testing/my-app'))->toBe(['testing/my-app/2026-10-04-03-30-00.zip']);

    $manifest = openCopy('testing/my-app/2026-10-04-03-30-00.zip');

    expect($manifest)->toMatchArray(['environment' => 'testing', 'anonymized' => true])
        ->and(DB::connection('copy')->table('users')->sole()->email)->toBe('users-1@anonymized.test')
        ->and(DB::connection('copy')->table('sessions')->count())->toBe(0);
});

it('never changes the source database', function () {
    $this->artisan('database-copy:export')->assertSuccessful();

    expect(DB::table('users')->sole()->email)->toBe('maria@gmail.com')
        ->and(DB::table('sessions')->count())->toBe(1);
});

it('deletes the SQLite anonymization database it created', function () {
    $this->artisan('database-copy:export')->assertSuccessful();

    expect(File::exists($this->directory.'/database_anonymized.sqlite'))->toBeFalse();
});

it('empties an existing SQLite anonymization database without deleting it', function () {
    touch($this->directory.'/database_anonymized.sqlite');

    $this->artisan('database-copy:export')->assertSuccessful();

    config()->set('database.connections.anonymization', array_merge(config('database.connections.testing'), [
        'database' => $this->directory.'/database_anonymized.sqlite',
    ]));

    expect(File::exists($this->directory.'/database_anonymized.sqlite'))->toBeTrue()
        ->and(Schema::connection('anonymization')->getTableListing())->toBe([]);
});

it('uses the configured anonymization database', function () {
    touch($this->directory.'/scratch.sqlite');
    config()->set('database-copy.anonymization.database', $this->directory.'/scratch.sqlite');

    $this->artisan('database-copy:export')->assertSuccessful();

    expect(File::exists($this->directory.'/database_anonymized.sqlite'))->toBeFalse();
});

it('anonymizes in the configured connection', function () {
    touch($this->directory.'/other-server.sqlite');
    config()->set('database.connections.other-server', array_merge(config('database.connections.testing'), [
        'database' => $this->directory.'/other-server.sqlite',
    ]));
    config()->set('database-copy.anonymization.connection', 'other-server');

    $this->artisan('database-copy:export')->assertSuccessful();

    openCopy('testing/my-app/2026-10-04-03-30-00.zip');

    expect(DB::connection('copy')->table('users')->sole()->email)->toBe('users-1@anonymized.test')
        ->and(File::exists($this->directory.'/other-server.sqlite'))->toBeTrue()
        ->and(Schema::connection('other-server')->getTableListing())->toBe([])
        ->and(File::exists($this->directory.'/database_anonymized.sqlite'))->toBeFalse();
});

it('refuses an anonymization connection that points to the source database', function (string $connection) {
    config()->set('database.connections.same-database', config('database.connections.testing'));
    config()->set('database-copy.anonymization.connection', $connection);

    $this->artisan('database-copy:export')
        ->expectsOutputToContain('must be different')
        ->assertFailed();

    expect(Storage::disk('backups')->allFiles())->toBe([])
        ->and(DB::table('users')->sole()->email)->toBe('maria@gmail.com');
})->with(['the source connection' => 'testing', 'another name for it' => 'same-database']);

it('fails when the anonymization connection does not exist', function () {
    config()->set('database-copy.anonymization.connection', 'missing');

    $this->artisan('database-copy:export')
        ->expectsOutputToContain('Database connection [missing] not configured')
        ->assertFailed();

    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

it('uploads the real data without anonymization', function () {
    $this->artisan('database-copy:export', ['--without-anonymization' => true])
        ->expectsOutputToContain('NOT anonymized')
        ->assertSuccessful();

    $manifest = openCopy('testing/my-app/2026-10-04-03-30-00.zip');

    expect($manifest['anonymized'])->toBeFalse()
        ->and(DB::connection('copy')->table('users')->sole()->email)->toBe('maria@gmail.com');
});

it('refuses to anonymize the source database itself', function () {
    config()->set('database-copy.anonymization.database', config('database.connections.testing.database'));

    $this->artisan('database-copy:export')
        ->expectsOutputToContain('must be different')
        ->assertFailed();

    expect(Storage::disk('backups')->allFiles())->toBe([])
        ->and(DB::table('users')->sole()->email)->toBe('maria@gmail.com');
});

it('uploads nothing when the anonymization fails', function () {
    config()->set('database-copy.anonymization.tables', ['users' => ['email' => 'not-a-strategy']]);

    $this->artisan('database-copy:export')
        ->expectsOutputToContain('not-a-strategy')
        ->assertFailed();

    expect(Storage::disk('backups')->allFiles())->toBe([])
        ->and(File::exists($this->directory.'/database_anonymized.sqlite'))->toBeFalse();
});

it('fails when the disk does not throw and the upload fails', function () {
    Storage::set('backups', Mockery::mock(Filesystem::class, ['writeStream' => false]));

    $this->artisan('database-copy:export')
        ->expectsOutputToContain('The copy testing/my-app/2026-10-04-03-30-00.zip could not be uploaded to the backups disk')
        ->doesntExpectOutputToContain('Done')
        ->assertFailed();

    expect(File::isEmptyDirectory($this->directory.'/temporary'))->toBeTrue();
});

it('requires a password', function () {
    config()->set('database-copy.password', null);

    $this->artisan('database-copy:export')
        ->expectsOutputToContain('DATABASE_COPY_PASSWORD')
        ->assertFailed();

    expect(Storage::disk('backups')->allFiles())->toBe([]);
});

it('cleans up the temporary directory', function () {
    $this->artisan('database-copy:export')->assertSuccessful();

    expect(File::isEmptyDirectory($this->directory.'/temporary'))->toBeTrue();
});
