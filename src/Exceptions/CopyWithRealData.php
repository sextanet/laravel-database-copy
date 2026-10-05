<?php

namespace SextaNet\LaravelDatabaseCopy\Exceptions;

use Exception;

class CopyWithRealData extends Exception
{
    public static function in(string $path): self
    {
        return new self("The copy {$path} is not anonymized: use --with-real-data to import it anyway.");
    }
}
