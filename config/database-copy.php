<?php

use Illuminate\Support\Str;

// config for SextaNet/LaravelDatabaseCopy
return [

    /*
    |--------------------------------------------------------------------------
    | Name
    |--------------------------------------------------------------------------
    | Folder of this app inside the disk. Copies are stored as
    | {environment}/{name}/{Y-m-d-H-i-s}.zip, so several apps and
    | environments can share the same bucket. The date uses the timezone of
    | the source app (config('app.timezone'), UTC by default).
    */
    'name' => env('DATABASE_COPY_NAME', Str::slug((string) env('APP_NAME', 'laravel'))),

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    | Database connection to export from and import into. Null uses the
    | default connection.
    */
    'connection' => env('DATABASE_COPY_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Disk
    |--------------------------------------------------------------------------
    | Filesystem disk where the copies are uploaded (e.g. a private S3 bucket).
    */
    'disk' => env('DATABASE_COPY_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Password
    |--------------------------------------------------------------------------
    | Every copy is encrypted (AES-256 zip) with this password. It must be the
    | same in every environment.
    */
    'password' => env('DATABASE_COPY_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Protected environments
    |--------------------------------------------------------------------------
    | Environments where database-copy:import refuses to run, because it
    | replaces the whole database.
    */
    'protected_environments' => ['production'],

    /*
    |--------------------------------------------------------------------------
    | Temporary directory
    |--------------------------------------------------------------------------
    | Local directory for dumps while they are processed. It is cleaned up
    | after every export and import.
    */
    'temporary_directory' => storage_path('app/database-copy'),

    /*
    |--------------------------------------------------------------------------
    | Anonymization
    |--------------------------------------------------------------------------
    | database: scratch database on the same server where the dump is
    |   anonymized before it leaves this environment. It is emptied after
    |   every export. Null uses "{database}_anonymized". It must exist,
    |   except with SQLite: the file is created and deleted after exporting.
    |
    | Strategies by column:
    |   first_name, last_name, name  → fake names
    |   email      → unique: {table}-{id}@{email_domain}
    |   rut        → valid and unique Chilean RUT
    |   phone      → +569 followed by 8 digits
    |   ip         → documentation IP (192.0.2.x, RFC 5737)
    |   user_agent → generic user agent
    |   password   → a different random password by row (nobody can log in)
    |   null       → NULL
    |   any Faker formatter (address, city, text, …)
    */
    'anonymization' => [

        'database' => env('DATABASE_COPY_ANONYMIZATION_DATABASE'),

        'locale' => env('DATABASE_COPY_LOCALE', 'es_ES'),

        'email_domain' => env('DATABASE_COPY_EMAIL_DOMAIN', 'anonymized.test'),

        // Rows with these emails keep their data, so the team can log in.
        'keep_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('DATABASE_COPY_KEEP_EMAILS', ''))))),

        'tables' => [
            'users' => [
                'name' => 'name',
                'email' => 'email',
                'password' => 'password',
                'remember_token' => 'null',
            ],
        ],

        'truncate' => [
            'password_reset_tokens',
            'personal_access_tokens',
            'sessions',
            'jobs',
            'failed_jobs',
        ],

    ],

];
