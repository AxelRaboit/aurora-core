<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Core\Storage\MessageHandler;

use Aurora\Core\Storage\ActiveStorageDiskProviderInterface;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Message\DeleteStoredFilesMessage;
use Aurora\Core\Storage\MessageHandler\DeleteStoredFilesHandler;
use Aurora\Core\Storage\StorageManager;
use Aurora\Tests\Unit\Core\Storage\InMemoryStorageAdapter;
use PHPUnit\Framework\TestCase;

final class DeleteStoredFilesHandlerTest extends TestCase
{
    public function testTheKeysGoOnEveryDisk(): void
    {
        // A file written before a disk switch is on the old disk, and the
        // key does not say which.
        $local = new InMemoryStorageAdapter(StorageDiskEnum::Local);
        $remote = new InMemoryStorageAdapter(StorageDiskEnum::R2);
        $local->objects = ['notes-markdown/1/a.png' => 'a', 'notes-markdown/1/kept.png' => 'k'];
        $remote->objects = ['notes-markdown/1/b.png' => 'b'];

        $this->handler($local, $remote)(new DeleteStoredFilesMessage(['notes-markdown/1/a.png', 'notes-markdown/1/b.png']));

        self::assertSame(['notes-markdown/1/kept.png' => 'k'], $local->objects);
        self::assertSame([], $remote->objects);
    }

    public function testADiskNobodyConfiguredIsNotAsked(): void
    {
        // Asking it would fail on every retry, for a disk that holds nothing.
        $local = new InMemoryStorageAdapter(StorageDiskEnum::Local);
        $remote = new InMemoryStorageAdapter(StorageDiskEnum::R2);
        $remote->ready = false;
        $local->objects = ['notes-markdown/1/a.png' => 'a'];
        $remote->objects = ['notes-markdown/1/a.png' => 'untouched'];

        $this->handler($local, $remote)(new DeleteStoredFilesMessage(['notes-markdown/1/a.png']));

        self::assertSame(['notes-markdown/1/a.png' => 'untouched'], $remote->objects);
        self::assertSame([], $local->objects);
    }

    private function handler(InMemoryStorageAdapter ...$adapters): DeleteStoredFilesHandler
    {
        return new DeleteStoredFilesHandler(new StorageManager($adapters, new class implements ActiveStorageDiskProviderInterface {
            public function activeDisk(): StorageDiskEnum
            {
                return StorageDiskEnum::Local;
            }
        }));
    }
}
