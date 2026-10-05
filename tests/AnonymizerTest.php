<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SextaNet\LaravelDatabaseCopy\Anonymizer;
use SextaNet\LaravelDatabaseCopy\Exceptions\UnknownStrategy;

beforeEach(function () {
    createUsersTable();
    createSessionsTable();

    config()->set('database-copy.anonymization.email_domain', 'anonymized.test');
    config()->set('database-copy.anonymization.tables', [
        'users' => [
            'name' => 'name',
            'email' => 'email',
            'rut' => 'rut',
            'phone' => 'phone',
            'birthday' => 'null',
            'password' => 'password',
            'remember_token' => 'null',
        ],
    ]);
    config()->set('database-copy.anonymization.truncate', ['sessions']);
});

function createUser(array $attributes = []): int
{
    return DB::table('users')->insertGetId(array_merge([
        'name' => 'María González',
        'email' => 'maria@gmail.com',
        'rut' => '15994071-3',
        'phone' => '+56912345678',
        'birthday' => '1985-04-10',
        'password' => Hash::make('password'),
        'remember_token' => 'remember-me',
    ], $attributes));
}

it('replaces the personal data of every row', function () {
    $id = createUser();

    app(Anonymizer::class)->run('testing');

    $user = DB::table('users')->find($id);

    expect($user->name)->not->toBe('María González')
        ->and($user->email)->toBe("users-{$id}@anonymized.test")
        ->and($user->rut)->toBe(Anonymizer::rut(1_000_000 + $id))
        ->and($user->phone)->toMatch('/^\+569\d{8}$/')
        ->and($user->phone)->not->toBe('+56912345678')
        ->and($user->birthday)->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeFalse();
});

it('generates unique emails and ruts', function () {
    createUser(['email' => 'a@gmail.com']);
    createUser(['email' => 'b@gmail.com']);
    createUser(['email' => 'c@gmail.com']);

    app(Anonymizer::class)->run('testing');

    expect(DB::table('users')->pluck('email')->unique())->toHaveCount(3)
        ->and(DB::table('users')->pluck('rut')->unique())->toHaveCount(3);
});

it('generates a different password for every row', function () {
    createUser(['email' => 'a@gmail.com']);
    createUser(['email' => 'b@gmail.com']);

    app(Anonymizer::class)->run('testing');

    $passwords = DB::table('users')->pluck('password');

    expect($passwords->unique())->toHaveCount(2)
        ->and(Hash::info($passwords->first())['algoName'])->toBe('bcrypt');
});

it('generates ruts with a valid check digit', function () {
    expect(Anonymizer::rut(12345678))->toBe('12345678-5')
        ->and(Anonymizer::rut(18376588))->toBe('18376588-4')
        ->and(Anonymizer::rut(15994071))->toBe('15994071-3')
        ->and(Anonymizer::rut(6))->toBe('6-K')
        ->and(Anonymizer::rut(14))->toBe('14-0');
});

it('keeps the rows of the configured emails untouched', function () {
    config()->set('database-copy.anonymization.keep_emails', ['seba@sextanet.cl']);
    $kept = createUser(['email' => 'seba@sextanet.cl', 'name' => 'Seba']);
    createUser(['email' => 'other@gmail.com']);

    app(Anonymizer::class)->run('testing');

    expect(DB::table('users')->find($kept))->name->toBe('Seba')->email->toBe('seba@sextanet.cl')
        ->and(DB::table('users')->where('email', 'other@gmail.com')->exists())->toBeFalse();
});

it('empties the truncate tables', function () {
    DB::table('sessions')->insert(['id' => 'abc', 'payload' => 'secret', 'last_activity' => 1]);

    app(Anonymizer::class)->run('testing');

    expect(DB::table('sessions')->count())->toBe(0);
});

it('returns a summary of what it did', function () {
    createUser();

    $summary = app(Anonymizer::class)->run('testing');

    expect($summary)->toBe([
        ['users', 'anonymized', 1],
        ['sessions', 'truncated', 0],
    ]);
});

it('skips tables and columns that do not exist', function () {
    config()->set('database-copy.anonymization.tables', [
        'users' => ['email' => 'email', 'missing_column' => 'null'],
        'missing_table' => ['email' => 'email'],
    ]);
    config()->set('database-copy.anonymization.truncate', ['sessions', 'missing_table']);
    $id = createUser();

    $summary = app(Anonymizer::class)->run('testing');

    expect(DB::table('users')->find($id)->email)->toBe("users-{$id}@anonymized.test")
        ->and($summary)->toHaveCount(2);
});

it('uses any faker formatter as a strategy', function () {
    config()->set('database-copy.anonymization.tables', ['users' => ['phone' => 'numerify']]);
    $id = createUser();

    app(Anonymizer::class)->run('testing');

    expect(DB::table('users')->find($id)->phone)->toMatch('/^\d{3}$/');
});

it('fails with an unknown strategy', function () {
    config()->set('database-copy.anonymization.tables', ['users' => ['name' => 'not-a-strategy']]);
    createUser();

    app(Anonymizer::class)->run('testing');
})->throws(UnknownStrategy::class, 'not-a-strategy');

it('anonymizes the given connection only', function () {
    $id = createUser();

    config()->set('database.connections.other', config('database.connections.testing'));
    config()->set('database.connections.other.database', $this->directory.'/other.sqlite');
    touch($this->directory.'/other.sqlite');
    createUsersTable('other');
    createSessionsTable('other');

    app(Anonymizer::class)->run('other');

    expect(DB::table('users')->find($id)->email)->toBe('maria@gmail.com');
});
