<?php

namespace SextaNet\LaravelDatabaseCopy\Commands;

use Illuminate\Console\Command;
use SextaNet\LaravelDatabaseCopy\Exporter;
use Throwable;

class ExportCommand extends Command
{
    public $signature = 'database-copy:export
                        {--without-anonymization : Upload the real data}';

    public $description = 'Upload an encrypted copy of this database, anonymized before it leaves this server';

    public function handle(Exporter $exporter): int
    {
        $anonymize = ! $this->option('without-anonymization');

        if (! $anonymize) {
            $this->warn('NOT anonymized: this copy contains the real data.');
        }

        try {
            $path = $exporter->export($anonymize, fn (string $message) => $this->line($message));
        } catch (Throwable $e) {
            $this->error("Nothing was uploaded: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Done: {$path}");

        return self::SUCCESS;
    }
}
