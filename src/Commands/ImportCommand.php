<?php

namespace SextaNet\LaravelDatabaseCopy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SextaNet\LaravelDatabaseCopy\Importer;
use Throwable;

class ImportCommand extends Command
{
    public $signature = 'database-copy:import
                        {--from=production : Environment to copy from}
                        {--file= : Specific copy to import, e.g. production/my-app/2026-10-04-03-30-00.zip (by default, the latest)}
                        {--with-real-data : Allow importing a copy that is not anonymized}
                        {--force : Do not ask for confirmation}';

    public $description = 'Replace this database with the latest copy of another environment';

    public function handle(Importer $importer): int
    {
        $environment = $this->laravel->environment();

        if (in_array($environment, config('database-copy.protected_environments'), true)) {
            $this->error("database-copy:import does not run in {$environment}: it would replace the real database.");

            return self::FAILURE;
        }

        try {
            $path = $this->option('file') ?: $importer->latest($this->option('from'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $connection = config('database-copy.connection') ?? config('database.default');
        $database = DB::connection($connection)->getDatabaseName();

        if (! $this->option('force') && ! $this->confirm("The whole database ({$database}) will be replaced by {$path}. Continue?")) {
            return self::FAILURE;
        }

        try {
            $importer->import($path, (bool) $this->option('with-real-data'), fn (string $message) => $this->line($message));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Running the migrations of this environment…');

        return $this->call('migrate', ['--database' => $connection, '--force' => true]);
    }
}
