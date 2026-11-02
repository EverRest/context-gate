<?php

declare(strict_types=1);

namespace ContextGate\Support;

use RuntimeException;

/**
 * The gate store failed (connection, timeout, lock or write). The original is kept as the previous exception for
 * the operator note, never in the message: connection details can carry hosts and passwords.
 *
 * @internal May change without notice.
 */
final class StoreUnavailable extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The gate store is unavailable.', 0, $previous);
    }
}
