<?php

declare(strict_types=1);

namespace Atannex\Bridge\Execution;

use Atannex\Bridge\Exceptions\BridgeException;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * Stores responses of idempotent operations by Idempotency-Key, scoped to
 * operation + user + tenant, so a retried write is answered from the store
 * instead of running twice.
 */
final class IdempotencyStore
{
    private readonly LockProvider $locks;

    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttl,
        private readonly int $lockSeconds = 300,
    ) {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Bridge idempotency needs a cache store that supports locks (redis, database, file, array, memcached, dynamodb).');
        }

        $this->locks = $store;
    }

    /**
     * Claim a key. Returns the stored response to replay, or a Claim to
     * complete once the operation has run.
     *
     * @param  array<string, mixed>  $input
     * @return array{replay: array{status: int, payload: array<string, mixed>}|null, claim: Claim|null}
     */
    public function begin(string $operation, string $scope, ?string $tenant, string $key, array $input): array
    {
        $id = 'bridge:idempotency:'.hash('sha256', implode('|', [$operation, $scope, $tenant ?? '-', $key]));
        $fingerprint = hash('sha256', json_encode($this->canonical($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $stored = $this->cache->get($id);

        if (is_array($stored)) {
            return ['replay' => $this->replay($stored, $fingerprint), 'claim' => null];
        }

        $lock = $this->locks->lock($id.':lock', $this->lockSeconds);

        if (! $lock->get()) {
            throw BridgeException::idempotencyInProgress();
        }

        // Another request may have finished between the read and the lock.
        $stored = $this->cache->get($id);

        if (is_array($stored)) {
            $lock->release();

            return ['replay' => $this->replay($stored, $fingerprint), 'claim' => null];
        }

        return ['replay' => null, 'claim' => new Claim($id, $fingerprint, $lock)];
    }

    /**
     * Remember the outcome. 409 and 429 are not stored: they describe a
     * transient state, and the client should be able to retry past them.
     *
     * @param  array<string, mixed>  $payload
     */
    public function complete(Claim $claim, int $status, array $payload): void
    {
        try {
            if ($status !== 409 && $status !== 429 && $status < 500) {
                $this->cache->put($claim->id, [
                    'fingerprint' => $claim->fingerprint,
                    'status' => $status,
                    'payload' => $payload,
                ], $this->ttl);
            }
        } finally {
            $claim->lock->release();
        }
    }

    public function abandon(Claim $claim): void
    {
        $claim->lock->release();
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function replay(array $stored, string $fingerprint): array
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw BridgeException::idempotencyKeyReused();
        }

        return ['status' => $stored['status'], 'payload' => $stored['payload']];
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return ['__file' => hash_file('sha256', $value->getRealPath()), 'size' => $value->getSize()];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => $this->canonical($item), $value);
    }
}
