<?php

namespace SextaNet\LaravelDatabaseCopy\Exceptions;

use Exception;

class NoCopiesFound extends Exception
{
    public static function in(string $folder): self
    {
        return new self("No copies found in {$folder}: run database-copy:export there first.");
    }

    public static function at(string $path): self
    {
        return new self("The copy {$path} could not be downloaded: check that it exists and the configuration and credentials of the disk.");
    }
}
