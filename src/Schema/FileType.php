<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Exceptions\OutputContractViolation;
use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;

/**
 * An uploaded file (input only). Size and type are checked by Laravel's file
 * rules, which look at the file's content, not just its name.
 */
final class FileType extends Type
{
    public const DEFAULT_MAX_KILOBYTES = 10240;

    private int $maxKilobytes = self::DEFAULT_MAX_KILOBYTES;

    /** @var list<string> */
    private array $mimeTypes = [];

    private bool $image = false;

    public function maxKilobytes(int $kilobytes): self
    {
        $copy = clone $this;
        $copy->maxKilobytes = $kilobytes;

        return $copy;
    }

    /**
     * Allowed MIME types, detected from the content, e.g. ['application/pdf'].
     *
     * @param  list<string>  $types
     */
    public function mimeTypes(array $types): self
    {
        $copy = clone $this;
        $copy->mimeTypes = $types;

        return $copy;
    }

    /** jpg, jpeg, png, bmp, gif or webp (no SVG, which can carry script). */
    public function image(): self
    {
        $copy = clone $this;
        $copy->image = true;

        return $copy;
    }

    /** @return list<mixed> */
    protected function rules(): array
    {
        $rules = ['file', 'max:'.$this->maxKilobytes];

        if ($this->image) {
            $rules[] = 'image';
        }

        if ($this->mimeTypes !== []) {
            $rules[] = 'mimetypes:'.implode(',', $this->mimeTypes);
        }

        return $rules;
    }

    protected function cast(mixed $value): mixed
    {
        return $value;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        throw new OutputContractViolation($path, 'download', 'file (files cannot be returned directly; return a Download)');
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        if ($direction === Direction::Output) {
            $writer->error('A file schema cannot be used in output. Return Schema::download() instead.');

            return 'never';
        }

        return $writer->import('BridgeFile');
    }

    public function label(): string
    {
        return 'file';
    }
}
