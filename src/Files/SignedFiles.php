<?php

declare(strict_types=1);

namespace Atannex\Bridge\Files;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Routing\UrlGenerator;
use InvalidArgumentException;

/**
 * Makes and opens signed download links.
 *
 * The link carries an encrypted token (disk, path, name, owner); the route
 * also checks Laravel's URL signature and expiry. Disks must be listed in
 * config('bridge.files.disks').
 */
final class SignedFiles
{
    /**
     * @param  list<string>  $disks
     */
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly Encrypter $encrypter,
        private readonly array $disks,
        private readonly int $ttl,
    ) {}

    /**
     * @return array{url: string, name: string, expires_at: string}
     */
    public function link(Download $download, ?Authenticatable $user, string $timezone): array
    {
        if (! in_array($download->disk, $this->disks, true)) {
            throw new InvalidArgumentException("Disk [{$download->disk}] is not in bridge.files.disks, so Bridge will not serve files from it.");
        }

        $expires = CarbonImmutable::now()->addSeconds($download->ttl ?? $this->ttl);

        $token = $this->encrypter->encrypt([
            'd' => $download->disk,
            'p' => $download->path,
            'n' => $download->fileName(),
            'u' => $download->public || $user === null ? null : (string) $user->getAuthIdentifier(),
        ]);

        return [
            'url' => $this->urls->temporarySignedRoute('bridge.files', $expires, ['token' => $token]),
            'name' => $download->fileName(),
            'expires_at' => $expires->setTimezone($timezone)->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{d: string, p: string, n: string, u: string|null}|null
     */
    public function open(string $token): ?array
    {
        try {
            $data = $this->encrypter->decrypt($token);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ! isset($data['d'], $data['p'], $data['n']) || ! in_array($data['d'], $this->disks, true)) {
            return null;
        }

        // The path was checked when the link was made; check again so a
        // leaked app key alone cannot walk the disk.
        if (str_starts_with($data['p'], '/') || str_contains($data['p'], "\0") || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $data['p']) === 1) {
            return null;
        }

        return $data;
    }
}
