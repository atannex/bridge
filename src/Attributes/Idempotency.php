<?php

declare(strict_types=1);

namespace Atannex\Bridge\Attributes;

/**
 * Whether a client may retry an operation. See PROTOCOL.md, Idempotency.
 */
enum Idempotency: string
{
    /** Reads only; retry freely. */
    case Safe = 'safe';

    /** Writes, but honours Idempotency-Key; retry only with a key. */
    case Idempotent = 'idempotent';

    /** Never retried automatically. */
    case Unsafe = 'unsafe';
}
