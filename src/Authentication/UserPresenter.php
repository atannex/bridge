<?php

declare(strict_types=1);

namespace Atannex\Bridge\Authentication;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The user as the auth endpoints show it: only allowlisted fields
 * (config bridge.auth.user_fields), or whatever a custom presenter returns.
 */
final class UserPresenter
{
    private ?Closure $custom = null;

    /**
     * @param  list<string>  $fields
     */
    public function __construct(private readonly array $fields) {}

    public function using(?Closure $presenter): void
    {
        $this->custom = $presenter;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Authenticatable $user): array
    {
        if ($this->custom !== null) {
            return ($this->custom)($user);
        }

        $out = [];

        foreach ($this->fields as $field) {
            $value = $user instanceof Model ? $user->getAttribute($field) : ($user->{$field} ?? null);
            $out[$field] = match (true) {
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                $value instanceof \BackedEnum => $value->value,
                is_scalar($value), $value === null => $value,
                default => null,
            };
        }

        return $out;
    }
}
