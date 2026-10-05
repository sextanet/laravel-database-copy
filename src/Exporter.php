<?php

namespace SextaNet\LaravelDatabaseCopy;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SextaNet\LaravelDatabaseCopy\Exceptions\UnsafeAnonymizationDatabase;
use SextaNet\LaravelDatabaseCopy\Exceptions\UploadFailed;
use Spatie\Backup\Tasks\Backup\DbDumperFactory;
use Spatie\DbDumper\Databases\MySql;
use Wnx\LaravelBackupRestore\DbImporterFactory;

class Exporter
{
    public const ANONYMIZATION_CONNECTION = 'database-copy-anonymization';

    public function __construct(
        private Archive $archive,
        private Anonymizer $anonymizer,
    ) {}

    /**
     * Dump the database, anonymize it in the anonymization database (optional), encrypt it and upload it.
     * The real data never leaves this server when anonymizing.
     *
     * @param  Closure(string): void|null  $output
     */
    public function export(bool $anonymize = true, ?Closure $output = null): string
    {
        $output ??= fn (string $message) => null;
        $connection = config('database-copy.connection') ?? config('database.default');
        $directory = config('database-copy.temporary_directory').'/'.Str::uuid();
        $name = now()->format('Y-m-d-H-i-s').'.zip';
        $path = app()->environment().'/'.config('database-copy.name').'/'.$name;

        File::ensureDirectoryExists($directory);

        try {
            $output('Dumping the database…');
            $dump = $this->dump($connection, $directory.'/'.Archive::DUMP);

            if ($anonymize) {
                $this->anonymize($connection, $dump, $output);
            }

            $zip = $this->archive->create($dump, [
                'environment' => app()->environment(),
                'anonymized' => $anonymize,
                'driver' => config("database.connections.{$connection}.driver"),
                'created_at' => now()->toIso8601String(),
            ], $directory.'/'.$name);

            File::delete($dump);

            $output("Uploading {$path}…");
            $this->upload($zip, $path);

            return $path;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * Import the dump into the anonymization database, anonymize it there and replace the dump with the anonymized one.
     *
     * @param  Closure(string): void  $output
     */
    private function anonymize(string $connection, string $dump, Closure $output): void
    {
        $created = $this->registerAnonymizationConnection($connection);
        $schema = DB::connection(self::ANONYMIZATION_CONNECTION)->getSchemaBuilder();

        try {
            $output('Importing into the anonymization database…');
            $schema->dropAllTables();
            DbImporterFactory::createFromConnection(self::ANONYMIZATION_CONNECTION)->importToDatabase($dump, self::ANONYMIZATION_CONNECTION);
            File::delete($dump);
            DB::purge(self::ANONYMIZATION_CONNECTION);

            $output('Anonymizing…');
            $this->anonymizer->run(self::ANONYMIZATION_CONNECTION);

            $output('Dumping the anonymized database…');
            $this->dump(self::ANONYMIZATION_CONNECTION, $dump);
        } finally {
            DB::connection(self::ANONYMIZATION_CONNECTION)->getSchemaBuilder()->dropAllTables();
            DB::purge(self::ANONYMIZATION_CONNECTION);

            if ($created) {
                File::delete(config('database.connections.'.self::ANONYMIZATION_CONNECTION.'.database'));
            }
        }
    }

    /**
     * Register the anonymization connection. Returns whether its SQLite file was created here, to delete it after.
     */
    private function registerAnonymizationConnection(string $connection): bool
    {
        $config = Arr::except((new ConfigurationUrlParser)->parseConfiguration(config("database.connections.{$connection}")), 'url');
        $database = config('database-copy.anonymization.database') ?: preg_replace('/(\.\w+)?$/', '_anonymized$1', $config['database'], 1);

        if ($database === $config['database']) {
            throw UnsafeAnonymizationDatabase::sameAsSource($database);
        }

        $created = $config['driver'] === 'sqlite' && ! File::exists($database);

        if ($created) {
            File::put($database, '');
        }

        config()->set('database.connections.'.self::ANONYMIZATION_CONNECTION, array_merge($config, ['database' => $database]));
        DB::purge(self::ANONYMIZATION_CONNECTION);

        return $created;
    }

    /**
     * Disks with 'throw' => false (e.g. Laravel's default s3 disk) return false instead of throwing.
     */
    private function upload(string $zip, string $path): void
    {
        $disk = config('database-copy.disk');
        $stream = fopen($zip, 'r');

        try {
            $uploaded = Storage::disk($disk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $uploaded) {
            throw UploadFailed::to($path, $disk);
        }
    }

    private function dump(string $connection, string $path): string
    {
        $dumper = DbDumperFactory::createFromConnection($connection);

        if ($dumper instanceof MySql) {
            $dumper->useSingleTransaction()->setGtidPurged('OFF');
        }

        $dumper->dumpToFile($path);

        return $path;
    }
}
