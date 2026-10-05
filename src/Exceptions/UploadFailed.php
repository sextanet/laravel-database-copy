<?php

namespace SextaNet\LaravelDatabaseCopy\Exceptions;

use Exception;

class UploadFailed extends Exception
{
    public static function to(string $path, string $disk): self
    {
        return new self("The copy {$path} could not be uploaded to the {$disk} disk: check its configuration and credentials.");
    }
}
