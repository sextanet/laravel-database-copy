<?php

namespace SextaNet\LaravelDatabaseCopy\Exceptions;

use Exception;

class MissingPassword extends Exception
{
    public function __construct()
    {
        parent::__construct('Set DATABASE_COPY_PASSWORD: every copy is encrypted with it.');
    }
}
