<?php

namespace SextaNet\LaravelDatabaseCopy\Exceptions;

use Exception;

class UnsafeAnonymizationDatabase extends Exception
{
    public static function sameAsSource(string $database): self
    {
        return new self("The anonymization database must be different from the source database ({$database}): set DATABASE_COPY_ANONYMIZATION_DATABASE.");
    }
}
