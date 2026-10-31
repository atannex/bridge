<?php

declare(strict_types=1);

namespace Atannex\Bridge\Generation;

final readonly class GeneratedContract
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $content,
        public array $errors,
        public array $warnings,
    ) {}

    public function failed(): bool
    {
        return $this->errors !== [];
    }
}
