<?php

declare(strict_types=1);

namespace Atannex\Bridge\Registry;

use Atannex\Bridge\Attributes\Access;
use Atannex\Bridge\Attributes\Idempotency;
use Atannex\Bridge\Attributes\Operation;
use Atannex\Bridge\Attributes\TenantMode;
use Atannex\Bridge\Contracts\Action;
use Atannex\Bridge\Exceptions\InvalidOperation;

/**
 * Everything Bridge knows about one operation, as plain data so it can be
 * compiled into bootstrap/cache/bridge.php.
 */
final readonly class OperationDefinition
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /**
     * @param  class-string<Action>  $class
     * @param  list<string>  $middleware
     */
    public function __construct(
        public string $name,
        public string $class,
        public Access $access = Access::Protected,
        public ?string $can = null,
        public ?string $on = null,
        public Idempotency $idempotency = Idempotency::Unsafe,
        public TenantMode $tenant = TenantMode::None,
        public ?string $rateLimit = null,
        public array $middleware = [],
        public string $version = '1.0',
        public ?string $deprecated = null,
        public ?string $description = null,
    ) {
        if (strlen($name) > 120 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw InvalidOperation::badName($name);
        }

        if (! class_exists($class) || ! is_subclass_of($class, Action::class)) {
            throw InvalidOperation::notAnAction($name, $class);
        }

        if ($on !== null && $can === null) {
            throw InvalidOperation::onWithoutCan($name);
        }
    }

    public static function fromAttribute(Operation $attribute, string $class): self
    {
        return self::fromArray(['class' => $class] + get_object_vars($attribute));
    }

    /**
     * Builds a definition from attribute-style options. Unknown keys are an
     * error so a typo cannot silently weaken a definition.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $known = ['name', 'class', 'access', 'can', 'on', 'idempotency', 'tenant', 'rateLimit', 'middleware', 'version', 'deprecated', 'description'];

        if ($unknown = array_diff(array_keys($data), $known)) {
            throw InvalidOperation::unknownOptions((string) ($data['name'] ?? '?'), array_values($unknown));
        }

        $enum = fn (string $enum, mixed $value, mixed $default) => match (true) {
            $value === null => $default,
            $value instanceof $enum => $value,
            default => $enum::from($value),
        };

        return new self(
            name: $data['name'],
            class: $data['class'],
            access: $enum(Access::class, $data['access'] ?? null, Access::Protected),
            can: $data['can'] ?? null,
            on: $data['on'] ?? null,
            idempotency: $enum(Idempotency::class, $data['idempotency'] ?? null, Idempotency::Unsafe),
            tenant: $enum(TenantMode::class, $data['tenant'] ?? null, TenantMode::None),
            rateLimit: $data['rateLimit'] ?? null,
            middleware: array_values($data['middleware'] ?? []),
            version: $data['version'] ?? '1.0',
            deprecated: $data['deprecated'] ?? null,
            description: $data['description'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'class' => $this->class,
            'access' => $this->access->value,
            'can' => $this->can,
            'on' => $this->on,
            'idempotency' => $this->idempotency->value,
            'tenant' => $this->tenant->value,
            'rateLimit' => $this->rateLimit,
            'middleware' => $this->middleware,
            'version' => $this->version,
            'deprecated' => $this->deprecated,
            'description' => $this->description,
        ];
    }

    public function requiresUser(): bool
    {
        return $this->access !== Access::Guest;
    }

    /** The client-facing auth level in the generated manifest. */
    public function authLabel(): string
    {
        return $this->requiresUser() ? 'user' : 'guest';
    }
}
