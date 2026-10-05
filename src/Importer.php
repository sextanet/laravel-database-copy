<?php

namespace SextaNet\LaravelDatabaseCopy;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SextaNet\LaravelDatabaseCopy\Exceptions\CopyWithRealData;
use SextaNet\LaravelDatabaseCopy\Exceptions\NoCopiesFound;
use Wnx\LaravelBackupRestore\DbImporterFactory;

class Importer
{
    public function __construct(
        private Archive $archive,
    ) {}

    public function latest(string $environment): string
    {
        $folder = $environment.'/'.config('database-copy.name');

        return collect(Storage::disk(config('database-copy.disk'))->files($folder))
            ->filter(fn (string $file) => str_ends_with($file, '.zip'))
            ->sort()
            ->last() ?? throw NoCopiesFound::in($folder);
    }

    /**
     * Replace this database with the copy. It is decrypted and checked before touching the database.
     *
     * @param  Closure(string): void|null  $output
     * @return array<string, mixed>
     */
    public function import(string $path, bool $allowRealData = false, ?Closure $output = null): array
    {
        $output ??= fn (string $message) => null;
        $connection = config('database-copy.connection') ?? config('database.default');
        $directory = config('database-copy.temporary_directory').'/'.Str::uuid();

        File::ensureDirectoryExists($directory);

        try {
            $output("Downloading {$path}…");
            $this->download($path, $directory.'/copy.zip');

            $manifest = $this->archive->extract($directory.'/copy.zip', $directory, $path);

            if (! ($manifest['anonymized'] ?? false) && ! $allowRealData) {
                throw CopyWithRealData::in($path);
            }

            $output('Replacing the database…');
            DB::connection($connection)->getSchemaBuilder()->dropAllTables();
            DbImporterFactory::createFromConnection($connection)->importToDatabase($directory.'/'.Archive::DUMP, $connection);
            DB::purge($connection);

            return $manifest;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Disks with 'throw' => false (e.g. Laravel's default s3 disk) return null instead of throwing.
     */
    private function download(string $path, string $destination): void
    {
        $stream = Storage::disk(config('database-copy.disk'))->readStream($path) ?? throw NoCopiesFound::at($path);
        $zip = fopen($destination, 'w');

        try {
            stream_copy_to_stream($stream, $zip);
        } finally {
            fclose($zip);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
