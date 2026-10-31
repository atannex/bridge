<?php

declare(strict_types=1);

namespace Atannex\Bridge\Execution;

use Atannex\Bridge\Exceptions\BridgeException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns any exception into a protocol error. Known Laravel exceptions map to
 * their codes; everything else becomes INTERNAL_ERROR, and only those are
 * marked for reporting. No exception message from an unknown exception ever
 * reaches the client.
 */
final class ErrorMapper
{
    /**
     * @return array{0: BridgeException, 1: bool} the error and whether to report it
     */
    public function map(Throwable $e): array
    {
        return match (true) {
            $e instanceof BridgeException => [$e, $e->status >= 500],
            $e instanceof ValidationException => [BridgeException::validation($e->errors()), false],
            $e instanceof AuthenticationException => [BridgeException::unauthenticated(), false],
            $e instanceof AuthorizationException => [$this->authorization($e), false],
            $e instanceof ModelNotFoundException, $e instanceof RecordsNotFoundException => [BridgeException::notFound(), false],
            $e instanceof TokenMismatchException => [BridgeException::csrfMismatch(), false],
            $e instanceof PostTooLargeException => [BridgeException::payloadTooLarge($this->postMaxSize()), false],
            $e instanceof InvalidSignatureException => [BridgeException::forbidden('This link is invalid or has expired.'), false],
            $e instanceof HttpExceptionInterface => $this->http($e),
            default => [BridgeException::internal($e), true],
        };
    }

    private function authorization(AuthorizationException $e): BridgeException
    {
        if ($e->status() === 404) {
            return BridgeException::notFound();
        }

        // Policy deny messages are written for users; Laravel's default is fine too.
        return BridgeException::forbidden($e->getMessage() !== '' ? $e->getMessage() : null);
    }

    /**
     * @return array{0: BridgeException, 1: bool}
     */
    private function http(HttpExceptionInterface $e): array
    {
        $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

        return match ($e->getStatusCode()) {
            401 => [BridgeException::unauthenticated(), false],
            403 => [BridgeException::forbidden(), false],
            404, 405 => [BridgeException::notFound(), false],
            413 => [BridgeException::payloadTooLarge($this->postMaxSize()), false],
            415 => [BridgeException::unsupportedMediaType(), false],
            419 => [BridgeException::csrfMismatch(), false],
            429 => [BridgeException::rateLimited(is_numeric($retryAfter) ? (int) $retryAfter : 60), false],
            503 => [BridgeException::unavailable(is_numeric($retryAfter) ? (int) $retryAfter : null), false],
            default => $e->getStatusCode() >= 500
                ? [BridgeException::internal($e), true]
                : [BridgeException::malformed(), false],
        };
    }

    private function postMaxSize(): int
    {
        $value = trim((string) ini_get('post_max_size'));
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
