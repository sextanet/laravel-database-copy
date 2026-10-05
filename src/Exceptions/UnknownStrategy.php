<?php

namespace SextaNet\LaravelDatabaseCopy\Exceptions;

use Exception;

class UnknownStrategy extends Exception
{
    public static function named(string $strategy): self
    {
        return new self("Unknown anonymization strategy: {$strategy}");
    }
}
