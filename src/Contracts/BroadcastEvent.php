<?php

declare(strict_types=1);

namespace Atannex\Bridge\Contracts;

use Atannex\Bridge\Schema\Type;

/**
 * A broadcast event with a typed payload. Use with the
 * BroadcastsThroughBridge trait and the #[Event] attribute.
 */
interface BroadcastEvent
{
    public static function payloadSchema(): Type;

    /** The value serialized through payloadSchema(). */
    public function payload(): mixed;
}
