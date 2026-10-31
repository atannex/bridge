<?php

declare(strict_types=1);

namespace Atannex\Bridge\Serialization;

use Atannex\Bridge\Exceptions\OutputContractViolation;
use Atannex\Bridge\Files\Download;
use Atannex\Bridge\Files\SignedFiles;
use Atannex\Bridge\Schema\Missing;
use Atannex\Bridge\Schema\ObjectType;
use Atannex\Bridge\Schema\Type;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;

/**
 * Turns an action's return value into JSON data through its output schema.
 *
 * The schema is an allowlist: only declared keys are read and sent. Models
 * are read key by key through getAttribute(), never through toArray(), so
 * hidden attributes, appended accessors and loaded relations the schema does
 * not name can never leak.
 *
 * One serializer per call; it knows the requesting user only so download
 * links can be bound to them.
 */
final class Serializer
{
    public function __construct(
        private readonly string $timezone,
        private readonly ?SignedFiles $files = null,
        private readonly ?Authenticatable $user = null,
    ) {}

    public function serialize(?Type $schema, mixed $value): mixed
    {
        if ($schema === null) {
            if ($value !== null) {
                throw new OutputContractViolation('', 'null (the action declares no output)', get_debug_type($value));
            }

            return null;
        }

        return $this->value($schema, $value, '');
    }

    public function value(Type $type, mixed $value, string $path): mixed
    {
        if ($value === null) {
            if ($type->isNullable()) {
                return null;
            }

            throw new OutputContractViolation($path, $type->label(), 'null');
        }

        return $type->serialize($value, $this, $path);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * Normalise an object-like value into something read() understands.
     */
    public function source(mixed $value, string $path, ObjectType $type): mixed
    {
        return match (true) {
            $value instanceof Model, is_array($value) => $value,
            $value instanceof JsonResource => $value->resolve(request()),
            $value instanceof Arrayable => $value->toArray(),
            $value instanceof JsonSerializable => is_array($data = $value->jsonSerialize())
                ? $data
                : throw new OutputContractViolation($path, $type->label(), get_debug_type($value)),
            is_object($value) => $value,
            default => throw new OutputContractViolation($path, $type->label(), get_debug_type($value)),
        };
    }

    public function read(mixed $source, string $key): mixed
    {
        if (is_array($source)) {
            return array_key_exists($key, $source) ? $source[$key] : Missing::value();
        }

        if ($source instanceof Model) {
            $known = array_key_exists($key, $source->getAttributes())
                || $source->relationLoaded($key)
                || $source->hasGetMutator($key)
                || $source->hasAttributeMutator($key)
                || self::isRelation($source, $key);

            return $known ? $source->getAttribute($key) : Missing::value();
        }

        // Public properties only, read from outside the object's scope.
        $properties = get_object_vars($source);

        return array_key_exists($key, $properties) ? $properties[$key] : Missing::value();
    }

    /**
     * Only a method whose declared return type is an Eloquent Relation is
     * treated as a relation to lazy-load. getAttribute() would otherwise call
     * any same-named method (restore(), notify(), publish(), ...) with its
     * side effects. Untyped relation methods must be loaded beforehand.
     */
    private static function isRelation(Model $model, string $key): bool
    {
        static $known = [];

        return $known[$model::class][$key] ??= (function () use ($model, $key): bool {
            if (! method_exists($model, $key)) {
                return false;
            }

            $type = (new \ReflectionMethod($model, $key))->getReturnType();

            return $type instanceof \ReflectionNamedType
                && ! $type->isBuiltin()
                && is_a($type->getName(), Relation::class, true);
        })();
    }

    /**
     * @return array{url: string, name: string, expires_at: string}
     */
    public function download(Download $download): array
    {
        if ($this->files === null) {
            throw new \LogicException('Downloads are not available in this serializer.');
        }

        return $this->files->link($download, $this->user, $this->timezone);
    }
}
