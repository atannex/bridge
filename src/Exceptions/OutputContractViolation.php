<?php

declare(strict_types=1);

namespace Atannex\Bridge\Exceptions;

use LogicException;

/**
 * An action returned something its output schema does not allow. This is a
 * server bug: it is reported and the client gets INTERNAL_ERROR, never the
 * offending data.
 */
final class OutputContractViolation extends LogicException
{
    public function __construct(public readonly string $path, string $expected, string $given)
    {
        parent::__construct(sprintf(
            'Bridge output at [%s] must be %s, %s given.',
            $path === '' ? '(root)' : $path,
            $expected,
            $given,
        ));
    }

    public function withOperation(string $operation): self
    {
        $copy = new self($this->path, '', '');
        $copy->message = "[{$operation}] ".$this->getMessage();

        return $copy;
    }
}
