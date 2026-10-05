<?php

use Illuminate\Support\Facades\File;
use SextaNet\LaravelDatabaseCopy\Archive;
use SextaNet\LaravelDatabaseCopy\Exceptions\InvalidArchive;
use SextaNet\LaravelDatabaseCopy\Exceptions\MissingPassword;

beforeEach(function () {
    File::put($this->directory.'/dump.sql', 'CREATE TABLE users (id integer);');
});

it('encrypts the dump with its manifest and opens it again', function () {
    $zip = app(Archive::class)->create($this->directory.'/dump.sql', ['anonymized' => true], $this->directory.'/copy.zip');

    $manifest = app(Archive::class)->extract($zip, $this->directory.'/extracted');

    expect($manifest)->toBe(['anonymized' => true])
        ->and(File::get($this->directory.'/extracted/dump.sql'))->toBe('CREATE TABLE users (id integer);');
});

it('encrypts every file inside the zip', function () {
    $zip = app(Archive::class)->create($this->directory.'/dump.sql', ['anonymized' => true], $this->directory.'/copy.zip');

    $archive = new ZipArchive;
    $archive->open($zip);

    expect($archive->statName('dump.sql')['encryption_method'])->toBe(ZipArchive::EM_AES_256)
        ->and($archive->statName('manifest.json')['encryption_method'])->toBe(ZipArchive::EM_AES_256)
        ->and($archive->getFromName('dump.sql'))->toBeFalse();
});

it('can not be opened with another password', function () {
    $zip = app(Archive::class)->create($this->directory.'/dump.sql', ['anonymized' => true], $this->directory.'/copy.zip');

    config()->set('database-copy.password', 'another-password');

    app(Archive::class)->extract($zip, $this->directory.'/extracted');
})->throws(InvalidArchive::class);

it('names the copy instead of its local path when it can not be decrypted', function () {
    $zip = app(Archive::class)->create($this->directory.'/dump.sql', ['anonymized' => true], $this->directory.'/copy.zip');

    config()->set('database-copy.password', 'another-password');

    app(Archive::class)->extract($zip, $this->directory.'/extracted', 'production/my-app/2026-10-04-03-30-00.zip');
})->throws(InvalidArchive::class, 'The copy production/my-app/2026-10-04-03-30-00.zip can not be decrypted');

it('requires a password', function () {
    config()->set('database-copy.password', null);

    app(Archive::class)->create($this->directory.'/dump.sql', ['anonymized' => true], $this->directory.'/copy.zip');
})->throws(MissingPassword::class);
