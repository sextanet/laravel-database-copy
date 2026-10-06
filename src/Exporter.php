<?php

namespace SextaNet\LaravelDatabaseCopy;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
        [$anonymization, $created] = $this->anonymizationConnection($connection);
        $schema = DB::connection($anonymization)->getSchemaBuilder();

        try {
            $output('Importing into the anonymization database…');
            $schema->dropAllTables();
            DbImporterFactory::createFromConnection($anonymization)->importToDatabase($dump, $anonymization);
            File::delete($dump);
            DB::purge($anonymization);

            $output('Anonymizing…');
            $this->anonymizer->run($anonymization);

            $output('Dumping the anonymized database…');
            $this->dump($anonymization, $dump);
        } finally {
            DB::connection($anonymization)->getSchemaBuilder()->dropAllTables();
            DB::purge($anonymization);

            if ($created) {
                File::delete(config("database.connections.{$anonymization}.database"));
            }
        }
    }

    /**
     * The connection where the dump is anonymized: the configured one (any host, user, …) or the source connection
     * pointing to the anonymization database. Also returns whether its SQLite file was created here, to delete it after.
     *
     * @return array{string, bool}
     */
    private function anonymizationConnection(string $connection): array
    {
        $source = $this->connectionConfiguration($connection);
        $name = config('database-copy.anonymization.connection');

        if ($name) {
            $config = $this->connectionConfiguration($name);
        } else {
            $name = self::ANONYMIZATION_CONNECTION;
            $config = array_merge($source, [
                'database' => config('database-copy.anonymization.database') ?: preg_replace('/(\.\w+)?$/', '_anonymized$1', $source['database'], 1),
            ]);
        }

        if ($name === $connection || $this->location($config) === $this->location($source)) {
            throw UnsafeAnonymizationDatabase::sameAsSource($source['database']);
        }

        config()->set("database.connections.{$name}", $config);
        DB::purge($name);

        $created = $config['driver'] === 'sqlite' && ! File::exists($config['database']);

        if ($created) {
            File::put($config['database'], '');
        }

        return [$name, $created];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfiguration(string $connection): array
    {
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Database connection [{$connection}] not configured.");
        }

        return Arr::except((new ConfigurationUrlParser)->parseConfiguration($config), 'url');
    }

    /**
     * Where a connection's data lives, to compare the anonymization database with the source.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    private function location(array $config): array
    {
        $host = Arr::first(Arr::wrap($config['write']['host'] ?? $config['host'] ?? null));

        return [
            $config['driver'],
            $host === 'localhost' ? '127.0.0.1' : $host,
            (string) ($config['port'] ?? ''),
            $config['driver'] === 'sqlite' ? realpath($config['database']) ?: $config['database'] : $config['database'],
        ];
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
