<?php

declare(strict_types=1);

namespace Gunner42\LaravelEetModule\Services;

use Illuminate\Database\QueryException;

/**
 * A failed write of a certificate is rethrown without its bindings, which
 * hold the PEM and the encrypted private key (R15, R103, Q75).
 *
 * @internal
 */
final class QueryErrors
{
    public static function withoutBindings(QueryException $exception): QueryException
    {
        // the message is built from the previous exception, so the driver exception is kept as the
        // cause; the original QueryException would bring the bindings back through its message
        return new QueryException($exception->getConnectionName(), $exception->getSql(), [], $exception->getPrevious() ?? $exception);
    }
}
