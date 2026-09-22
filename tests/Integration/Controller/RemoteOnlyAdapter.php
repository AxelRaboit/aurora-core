<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Controller;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Tests\Integration\Core\Storage\SecondDiskAdapter;
use DateTimeImmutable;
use Generator;

/**
 * A backend holding exactly one object, and no filesystem underneath.
 *
 * It answers for the R2 disk while being nothing of the sort, the same trick
 * {@see SecondDiskAdapter} plays for the
 * relocation tests, and for the same reason: what the serving tests exercise is
 * which headers the streaming branch writes, which does not care what is on the
 * other side. Keeping it local means they run in CI with no credentials.
 *
 * Notably it does NOT implement `LocalPathAware`. That interface is what the
 * serve controller branches on, so a fake that implemented it would be served
 * down the local path and prove nothing.
 */
final readonly class RemoteOnlyAdapter implements StorageAdapterInterface
{
    public function __construct(
        private string $contents,
    ) {}

    public function disk(): StorageDiskEnum
    {
        return StorageDiskEnum::R2;
    }

    public function isReady(): bool
    {
        return true;
    }

    public function exists(string $key): bool
    {
        return true;
    }

    public function read(string $key): string
    {
        return $this->contents;
    }

    public function readStream(string $key): Generator
    {
        yield $this->contents;
    }

    public function stat(string $key): ?StoredObject
    {
        return new StoredObject(
            key: $key,
            size: mb_strlen($this->contents, '8bit'),
            lastModifiedAt: new DateTimeImmutable(),
        );
    }

    public function writeFromLocalFile(string $key, string $sourceAbsolutePath): void {}

    public function write(string $key, string $contents): void {}

    public function copyToLocalFile(string $key, string $targetAbsolutePath): void {}

    public function delete(string $key): void {}

    /** @param list<string> $keys */
    public function deleteMany(array $keys): void {}

    public function list(string $prefix): Generator
    {
        yield from [];
    }
}
