<?php

declare(strict_types=1);

namespace Atannex\Bridge\Exceptions;

/**
 * Throw from an action when the request clashes with current state (409).
 * The message and details are sent to the client, so keep them user-safe.
 */
final class Conflict extends BridgeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(string $message = 'The request conflicts with the current state.', ?array $details = null)
    {
        parent::__construct('CONFLICT', 409, $message, $details);
    }
}
