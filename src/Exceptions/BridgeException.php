<?php

declare(strict_types=1);

namespace Atannex\Bridge\Exceptions;

use Atannex\Bridge\Http\Envelope;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An error with a protocol code, HTTP status and a message that is safe to
 * show to clients. Anything that is not a BridgeException is reported and
 * sent as a generic INTERNAL_ERROR.
 */
class BridgeException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>|null  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        public readonly ?array $details = null,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function malformed(string $message = 'The request body is malformed.'): self
    {
        return new self('MALFORMED_REQUEST', 400, $message);
    }

    /**
     * @param  list<string>  $supported
     */
    public static function unsupportedVersion(array $supported): self
    {
        return new self('UNSUPPORTED_VERSION', 400, 'This protocol version is not supported.', ['supported' => $supported]);
    }

    public static function httpsRequired(): self
    {
        return new self('HTTPS_REQUIRED', 403, 'Requests must use HTTPS.');
    }

    public static function unauthenticated(): self
    {
        return new self('UNAUTHENTICATED', 401, 'You need to sign in to do this.');
    }

    public static function forbidden(?string $message = null): self
    {
        return new self('FORBIDDEN', 403, $message ?? 'You are not allowed to do this.');
    }

    public static function notFound(?string $message = null): self
    {
        return new self('NOT_FOUND', 404, $message ?? 'The requested resource was not found.');
    }

    public static function tenantRequired(): self
    {
        return new self('TENANT_REQUIRED', 400, 'Choose a workspace for this request.');
    }

    public static function tenantForbidden(): self
    {
        return new self('TENANT_FORBIDDEN', 403, 'You do not have access to that workspace.');
    }

    public static function payloadTooLarge(int $limit): self
    {
        return new self('PAYLOAD_TOO_LARGE', 413, 'The request is too large.', ['limit_bytes' => $limit]);
    }

    public static function unsupportedMediaType(): self
    {
        return new self('UNSUPPORTED_MEDIA_TYPE', 415, 'Send application/json or multipart/form-data.');
    }

    public static function csrfMismatch(): self
    {
        return new self('CSRF_MISMATCH', 419, 'Your session has expired. Refresh and try again.');
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validation(array $errors, string $message = 'The supplied data is invalid.'): self
    {
        return new self('VALIDATION_FAILED', 422, $message, $errors);
    }

    public static function twoFactorFailed(): self
    {
        return new self('TWO_FACTOR_FAILED', 422, 'The two-factor code is invalid or has expired.');
    }

    public static function rateLimited(int $retryAfter): self
    {
        $retryAfter = max(1, $retryAfter);

        return new self('RATE_LIMITED', 429, 'Too many requests. Try again later.', ['retry_after' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('IDEMPOTENCY_KEY_REUSED', 409, 'This idempotency key was already used with different input.');
    }

    public static function idempotencyInProgress(): self
    {
        return new self('IDEMPOTENCY_IN_PROGRESS', 409, 'A request with this idempotency key is still running.', null, ['Retry-After' => '1']);
    }

    public static function internal(?\Throwable $previous = null): self
    {
        return new self('INTERNAL_ERROR', 500, 'Something went wrong on our side.', null, [], $previous);
    }

    public static function unavailable(?int $retryAfter = null): self
    {
        return new self('SERVICE_UNAVAILABLE', 503, 'The service is temporarily unavailable.', null, $retryAfter ? ['Retry-After' => (string) $retryAfter] : []);
    }

    /**
     * Lets Laravel's exception handler render a BridgeException thrown outside
     * the executor (auth endpoints, downloads) in protocol form.
     */
    public function render(Request $request): JsonResponse
    {
        return Envelope::error($request, $this);
    }
}
