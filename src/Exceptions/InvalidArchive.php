<?php

namespace SextaNet\LaravelDatabaseCopy\Exceptions;

use Exception;

class InvalidArchive extends Exception
{
    public static function cannotOpen(string $path): self
    {
        return new self("The copy {$path} can not be opened.");
    }

    public static function cannotDecrypt(string $path): self
    {
        return new self("The copy {$path} can not be decrypted: check that DATABASE_COPY_PASSWORD is the same in both environments.");
    }
}
