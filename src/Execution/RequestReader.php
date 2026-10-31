<?php

declare(strict_types=1);

namespace Atannex\Bridge\Execution;

use Atannex\Bridge\Exceptions\BridgeException;
use Atannex\Bridge\Protocol;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use stdClass;

/**
 * Reads the raw `input` object from a JSON or multipart call body, enforcing
 * size, shape and nesting limits before anything else looks at it.
 */
final class RequestReader
{
    private const FILE_PATH = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+){0,7}$/';

    public function __construct(
        private readonly int $jsonLimit,
        private readonly int $multipartLimit,
        private readonly int $maxFiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(Request $request): array
    {
        $type = strtolower((string) $request->headers->get('Content-Type'));
        $length = (int) $request->server('CONTENT_LENGTH', 0);

        if (str_starts_with($type, 'multipart/form-data')) {
            if ($length > $this->multipartLimit) {
                throw BridgeException::payloadTooLarge($this->multipartLimit);
            }

            return $this->multipart($request);
        }

        if ($length > $this->jsonLimit) {
            throw BridgeException::payloadTooLarge($this->jsonLimit);
        }

        $raw = $request->getContent();

        if (strlen($raw) > $this->jsonLimit) {
            throw BridgeException::payloadTooLarge($this->jsonLimit);
        }

        if (trim($raw) === '') {
            return [];
        }

        if ($type !== '' && ! str_starts_with($type, 'application/json') && preg_match('#^application/[a-z0-9.+-]+\+json#', $type) !== 1) {
            throw BridgeException::unsupportedMediaType();
        }

        $body = $this->decode($raw);

        if (! $body instanceof stdClass) {
            throw BridgeException::malformed('The request body must be a JSON object.');
        }

        $keys = array_keys(get_object_vars($body));

        if (array_diff($keys, ['input']) !== []) {
            throw BridgeException::malformed('The request body may only contain "input".');
        }

        if (! property_exists($body, 'input')) {
            return [];
        }

        return $this->inputFrom($body->input, $raw, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function multipart(Request $request): array
    {
        if (array_diff(array_keys($request->request->all()), ['input']) !== []
            || array_diff(array_keys($request->files->all()), ['files']) !== []) {
            throw BridgeException::malformed('A multipart call may only contain an "input" field and "files[...]" parts.');
        }

        $raw = $request->request->get('input', '{}');

        if (! is_string($raw)) {
            throw BridgeException::malformed('The "input" field must be a JSON string.');
        }

        $input = $this->inputFrom($this->decode($raw), $raw, false);
        $files = $request->file('files', []);

        if (! is_array($files)) {
            throw BridgeException::malformed('Files must be sent as files[<path>].');
        }

        if (count($files) > $this->maxFiles) {
            throw BridgeException::malformed("A call may carry at most {$this->maxFiles} files.");
        }

        // A chunked upload has no Content-Length, so count what actually arrived.
        $received = strlen($raw);

        foreach ($files as $path => $file) {
            if (! $file instanceof UploadedFile || preg_match(self::FILE_PATH, (string) $path) !== 1) {
                throw BridgeException::malformed('Files must be sent as files[<path>] with a dot path such as files[avatar] or files[attachments.0].');
            }

            $received += (int) $file->getSize();

            if ($received > $this->multipartLimit) {
                throw BridgeException::payloadTooLarge($this->multipartLimit);
            }

            Arr::set($input, (string) $path, $file);
        }

        return $input;
    }

    private function decode(string $raw): mixed
    {
        try {
            return json_decode($raw, false, Protocol::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw BridgeException::malformed('The request body is not valid JSON, or nests too deeply.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inputFrom(mixed $input, string $raw, bool $wrapped): array
    {
        if (! $input instanceof stdClass) {
            throw BridgeException::malformed('"input" must be a JSON object.');
        }

        // Decode once more as arrays: objects-vs-lists has been checked above.
        $decoded = json_decode($raw, true, Protocol::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);

        return $wrapped ? $decoded['input'] : $decoded;
    }
}
