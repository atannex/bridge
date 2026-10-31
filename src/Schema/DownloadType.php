<?php

declare(strict_types=1);

namespace Atannex\Bridge\Schema;

use Atannex\Bridge\Files\Download;
use Atannex\Bridge\Generation\TypeScriptWriter;
use Atannex\Bridge\Serialization\Serializer;

/**
 * A signed, expiring link to a stored file (output only):
 * `{ url, name, expires_at }`.
 */
final class DownloadType extends Type
{
    /** @return list<mixed> */
    protected function rules(): array
    {
        return ['prohibited'];
    }

    protected function cast(mixed $value): mixed
    {
        return $value;
    }

    public function serialize(mixed $value, Serializer $serializer, string $path): mixed
    {
        if (! $value instanceof Download) {
            $this->violation($path, Download::class, $value);
        }

        return $serializer->download($value);
    }

    public function typescript(TypeScriptWriter $writer, Direction $direction): string
    {
        if ($direction === Direction::Input) {
            $writer->error('Schema::download() is output only.');

            return 'never';
        }

        return $writer->import('DownloadLink');
    }

    public function label(): string
    {
        return 'download';
    }
}
