<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Exceptions;

final class ReversalEnvironmentMismatch extends EetException
{
    public function __construct(string $message = 'The original sale was registered in another environment or sending mode.')
    {
        parent::__construct($message);
    }
}
