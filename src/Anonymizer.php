<?php

namespace SextaNet\LaravelDatabaseCopy;

use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SextaNet\LaravelDatabaseCopy\Exceptions\UnknownStrategy;

class Anonymizer
{
    private Connection $connection;

    private Generator $faker;

    /**
     * Replace the personal data of the given connection following config('database-copy.anonymization').
     *
     * @return list<array{string, string, int}>
     */
    public function run(string $connection): array
    {
        $this->connection = DB::connection($connection);
        $this->faker = Factory::create(config('database-copy.anonymization.locale'));

        $summary = [];

        foreach (config('database-copy.anonymization.tables') as $table => $columns) {
            if ($this->connection->getSchemaBuilder()->hasTable($table)) {
                $summary[] = [$table, 'anonymized', $this->anonymizeTable($table, $columns)];
            }
        }

        foreach (config('database-copy.anonymization.truncate') as $table) {
            if ($this->connection->getSchemaBuilder()->hasTable($table)) {
                $summary[] = [$table, 'truncated', $this->connection->table($table)->delete()];
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, string>  $columns
     */
    private function anonymizeTable(string $table, array $columns): int
    {
        $existing = $this->connection->getSchemaBuilder()->getColumnListing($table);
        $columns = array_intersect_key($columns, array_flip($existing));
        $emailColumns = array_keys($columns, 'email', true);
        $keepEmails = config('database-copy.anonymization.keep_emails');
        $rows = 0;

        $this->connection->table($table)
            ->when($keepEmails, function ($query) use ($emailColumns, $keepEmails) {
                foreach ($emailColumns as $column) {
                    $query->where(fn ($query) => $query->whereNull($column)->orWhereNotIn($column, $keepEmails));
                }
            })
            ->chunkById(500, function ($records) use ($table, $columns, &$rows) {
                foreach ($records as $record) {
                    $values = [];

                    foreach ($columns as $column => $strategy) {
                        $values[$column] = $this->value($strategy, $table, (int) $record->id);
                    }

                    $this->connection->table($table)->where('id', $record->id)->update($values);
                    $rows++;
                }
            });

        return $rows;
    }

    private function value(string $strategy, string $table, int $id): mixed
    {
        return match ($strategy) {
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'name' => $this->faker->firstName().' '.$this->faker->lastName(),
            'email' => Str::slug($table)."-{$id}@".config('database-copy.anonymization.email_domain'),
            'rut' => self::rut(1_000_000 + $id),
            'phone' => '+569'.str_pad((string) (10_000_000 + ($id % 90_000_000)), 8, '0', STR_PAD_LEFT),
            'ip' => '192.0.2.'.($id % 254 + 1),
            'user_agent' => 'Mozilla/5.0 (anonymized)',
            'password' => self::password(),
            'null' => null,
            default => $this->fakerValue($strategy),
        };
    }

    /**
     * A different random password for every row, hashed with the configured driver at its minimum cost: nobody can
     * log in with it and hashing thousands of rows stays fast.
     */
    private static function password(): string
    {
        return Hash::make(Str::random(40), ['rounds' => 4, 'memory' => 1024, 'time' => 1, 'threads' => 1]);
    }

    private function fakerValue(string $strategy): mixed
    {
        try {
            return $this->faker->format($strategy);
        } catch (InvalidArgumentException) {
            throw UnknownStrategy::named($strategy);
        }
    }

    /**
     * Chilean RUT with a valid check digit (modulo 11), e.g. 12345678-5.
     */
    public static function rut(int $number): string
    {
        $sum = 0;
        $factor = 2;

        foreach (array_reverse(str_split((string) $number)) as $digit) {
            $sum += (int) $digit * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $checkDigit = 11 - ($sum % 11);

        return $number.'-'.match ($checkDigit) {
            11 => '0',
            10 => 'K',
            default => (string) $checkDigit,
        };
    }
}
