<?php

declare(strict_types=1);

namespace Atannex\Bridge\Files;

use InvalidArgumentException;

/**
 * A stored file an action wants to hand out. Bridge turns it into a signed,
 * expiring link; the disk and path travel encrypted inside the link, so the
 * client never sees or chooses them.
 *
 *     return ['invoice' => Download::fromDisk('local', $order->invoice_path)->as('invoice.pdf')];
 */
final readonly class Download
{
    private function __construct(
        public string $disk,
        public string $path,
        public ?string $name,
        public ?int $ttl,
        public bool $public,
    ) {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $path) === 1) {
            throw new InvalidArgumentException('A download path must be relative to its disk and may not contain "..".');
        }
    }

    public static function fromDisk(string $disk, string $path): self
    {
        return new self($disk, $path, null, null, false);
    }

    /** The file name the client saves it as. */
    public function as(string $name): self
    {
        return new self($this->disk, $this->path, basename(str_replace('\\', '/', $name)), $this->ttl, $this->public);
    }

    public function expiresIn(int $seconds): self
    {
        return new self($this->disk, $this->path, $this->name, $seconds, $this->public);
    }

    /**
     * Let anyone holding the link download it until it expires. By default a
     * link only works for the user it was made for.
     */
    public function shareable(): self
    {
        return new self($this->disk, $this->path, $this->name, $this->ttl, true);
    }

    public function fileName(): string
    {
        return $this->name ?? basename($this->path);
    }
}
