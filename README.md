# Laravel Database Copy

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sextanet/laravel-database-copy.svg?style=flat-square)](https://packagist.org/packages/sextanet/laravel-database-copy)
[![GitHub Tests Action Status](https://github.com/sextanet/laravel-database-copy/actions/workflows/run-tests.yml/badge.svg)](https://github.com/sextanet/laravel-database-copy/actions?query=workflow%3Arun-tests+branch%3Amain)

Copy a database between environments (e.g. production → staging) through a disk such as S3. The copy is anonymized
**before it leaves the source server**, so the real data never reaches the bucket nor the other environment.

```
Production ──database-copy:export──▶ dump → anonymization database (same server) → anonymize → dump → encrypt
                                                                                                   │
                                                          disk: {environment}/{name}/{Y-m-d-H-i-s}.zip
                                                                                                   │
Staging    ◀──database-copy:import── decrypt → check it is anonymized → replace database → migrate ┘
```

## Installation

```bash
composer require sextanet/laravel-database-copy
php artisan vendor:publish --tag="database-copy-config"
```

```dotenv
DATABASE_COPY_DISK=s3
DATABASE_COPY_PASSWORD=          # the SAME in every environment
DATABASE_COPY_NAME=my-app        # folder of this app in the disk (default: slug of APP_NAME)

# Source environment only
DATABASE_COPY_ANONYMIZATION_DATABASE=   # default: {database}_anonymized
DATABASE_COPY_EMAIL_DOMAIN=anonymized.test
DATABASE_COPY_KEEP_EMAILS=seba@sextanet.cl   # rows that keep their data, comma separated
```

The source environment needs `mysqldump`/`mysql` (or `sqlite3`, `pg_dump`/`psql`) and an **empty anonymization
database** on the same server that the database user can write to:

```sql
CREATE DATABASE my_app_anonymized;
GRANT ALL PRIVILEGES ON my_app_anonymized.* TO 'my_app'@'localhost';
```

It is emptied after every export, even when something fails. With SQLite there is nothing to create: the file
(`database/database_anonymized.sqlite` by default) is created and deleted on every export.

## Anonymization

`config/database-copy.php` is the source of truth of what is personal data. Add every table or column with personal
data there:

```php
'tables' => [
    'users' => [
        'name' => 'first_name',
        'last_name' => 'last_name',
        'rut' => 'rut',
        'email' => 'email',
        'phone' => 'phone',
        'birthday' => 'null',
        'password' => 'password',
        'remember_token' => 'null',
    ],
    'comments' => [
        'email' => 'email',
        'ip' => 'ip',
        'user_agent' => 'user_agent',
        'address' => 'address', // any Faker formatter
    ],
],

'truncate' => ['sessions', 'password_reset_tokens', 'personal_access_tokens', 'jobs', 'failed_jobs'],
```

Tables or columns that do not exist are skipped. Anonymized tables need an `id` column.

## Usage

```bash
# Source (production): upload an anonymized copy
php artisan database-copy:export

# Target (staging, local): replace this database with the latest copy from production
php artisan database-copy:import
php artisan database-copy:import --from=staging
php artisan database-copy:import --file=production/my-app/2026-10-04-03-30-00.zip
```

Copies are named with the date of the source app in its timezone (`config('app.timezone')`, UTC by default).

Schedule the export in the source environment:

```php
Schedule::command('database-copy:export')->dailyAt('03:30');
```

Safety:

- `database-copy:import` never runs in `protected_environments` (`production` by default) and asks for confirmation
  (unless `--force`).
- It refuses a copy that is not anonymized (`database-copy:export --without-anonymization`) unless `--with-real-data`.
- The copy is decrypted and checked before the database is touched.
- The export refuses to anonymize when the anonymization database is the source database.

## Testing

```bash
composer test
```

## Credits

- [SextaNet](https://github.com/sextanet)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
