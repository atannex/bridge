<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

/**
 * Which way a value travels. Some types differ: a file is a BridgeFile going
 * in and cannot come out; optional output keys stay optional.
 */
enum Direction: string
{
    case Input = 'input';
    case Output = 'output';
}
