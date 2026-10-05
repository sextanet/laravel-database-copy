<?php

namespace SextaNet\LaravelDatabaseCopy;

use Illuminate\Support\Facades\File;
use SextaNet\LaravelDatabaseCopy\Exceptions\InvalidArchive;
use SextaNet\LaravelDatabaseCopy\Exceptions\MissingPassword;
use ZipArchive;

class Archive
{
    public const DUMP = 'dump.sql';

    public const MANIFEST = 'manifest.json';

    /**
     * Zip the dump with its manifest, both encrypted with AES-256.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function create(string $dump, array $manifest, string $path): string
    {
        $password = $this->password();
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw InvalidArchive::cannotOpen($path);
        }

        $zip->addFile($dump, self::DUMP);
        $zip->addFromString(self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT));

        foreach ([self::DUMP, self::MANIFEST] as $name) {
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
        }

        $zip->close();

        return $path;
    }

    /**
     * Extract the copy into the directory and return its manifest. The name (e.g. its path in the disk) is used in
     * the error messages instead of the local path.
     *
     * @return array<string, mixed>
     */
    public function extract(string $path, string $directory, ?string $name = null): array
    {
        $name ??= $path;
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw InvalidArchive::cannotOpen($name);
        }

        $zip->setPassword($this->password());
        File::ensureDirectoryExists($directory);

        if (! $zip->extractTo($directory, [self::DUMP, self::MANIFEST])) {
            $zip->close();

            throw InvalidArchive::cannotDecrypt($name);
        }

        $zip->close();

        return json_decode(File::get($directory.'/'.self::MANIFEST), true);
    }

    private function password(): string
    {
        return (string) config('database-copy.password') ?: throw new MissingPassword;
    }
}
