<?php

declare(strict_types=1);

namespace Atannex\Bridge\Exceptions;

/**
 * Throw from an action when a record does not exist or the user must not
 * learn that it does (404).
 */
final class NotFound extends BridgeException
{
    public function __construct(string $message = 'The requested resource was not found.')
    {
        parent::__construct('NOT_FOUND', 404, $message);
    }
}
