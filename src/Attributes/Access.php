<?php

declare(strict_types=1);

namespace Atannex\Bridge\Attributes;

/**
 * Who may call an operation.
 */
enum Access: string
{
    /** Anyone, signed in or not. */
    case Guest = 'guest';

    /** Any signed-in user. A deliberate choice, never a default. */
    case Authenticated = 'authenticated';

    /**
     * Signed in AND allowed by a `can` ability and/or the Authorizes
     * contract. With neither, every call is refused (deny by default).
     */
    case Protected = 'protected';
}
