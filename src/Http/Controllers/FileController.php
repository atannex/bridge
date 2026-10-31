<?php

declare(strict_types=1);

namespace Atannex\Bridge\Http\Controllers;

use Atannex\Bridge\Authentication\PrincipalResolver;
use Atannex\Bridge\Exceptions\BridgeException;
use Atannex\Bridge\Files\SignedFiles;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a file behind a signed link made by a Download. The route checks the
 * signature and expiry; this checks the encrypted token and, for links bound
 * to a user, that the same user is asking. Every refusal looks like a 404 so
 * a link reveals nothing about files it does not grant.
 */
final class FileController
{
    public function __invoke(Request $request, string $token, SignedFiles $files, PrincipalResolver $principals, Filesystems $disks): StreamedResponse
    {
        $data = $files->open($token) ?? throw BridgeException::notFound();

        if ($data['u'] !== null) {
            [$user] = $principals->resolve($request);

            if ($user === null || ! hash_equals($data['u'], (string) $user->getAuthIdentifier())) {
                throw BridgeException::notFound();
            }
        }

        $disk = $disks->disk($data['d']);

        if (! $disk->exists($data['p'])) {
            throw BridgeException::notFound();
        }

        return $disk->download($data['p'], $data['n'], [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
