<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\Document\Serializer;

use Aurora\Core\Testing\Concern\CreatesStorageUrlGenerators;
use Aurora\Module\Ged\Document\Entity\DocumentVersionInterface;
use Aurora\Module\Ged\Document\Serializer\DocumentVersionSerializer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DocumentVersionSerializerTest extends TestCase
{
    use CreatesStorageUrlGenerators;

    private function makeVersion(
        int $id = 3,
        int $versionNumber = 2,
        string $fileName = 'contract.pdf',
        string $filePath = 'ged/2026/05/contract.pdf',
        string $originalName = 'Original Contract.pdf',
        string $fileMime = 'application/pdf',
        int $fileSize = 12345,
        ?string $note = 'Updated contract',
        string $createdAt = '2025-03-01T08:00:00+00:00',
    ): DocumentVersionInterface {
        $version = $this->createStub(DocumentVersionInterface::class);
        $version->method('getId')->willReturn($id);
        $version->method('getVersionNumber')->willReturn($versionNumber);
        $version->method('getFileName')->willReturn($fileName);
        $version->method('getFilePath')->willReturn($filePath);
        $version->method('getOriginalName')->willReturn($originalName);
        $version->method('getMimeType')->willReturn($fileMime);
        $version->method('getSize')->willReturn($fileSize);
        $version->method('getNote')->willReturn($note);
        $version->method('getCreatedAt')->willReturn(new DateTimeImmutable($createdAt));

        return $version;
    }

    public function testSerializeReturnsAllExpectedFields(): void
    {
        $result = (new DocumentVersionSerializer($this->makeStubbedUrlGenerator()))->serialize($this->makeVersion());

        self::assertSame(3, $result['id']);
        self::assertSame(2, $result['versionNumber']);
        self::assertSame('contract.pdf', $result['fileName']);
        self::assertSame('/uploads/ged/2026/05/contract.pdf', $result['fileUrl']);
        self::assertSame('application/pdf', $result['fileMime']);
        self::assertSame(12345, $result['fileSize']);
        self::assertSame('Updated contract', $result['note']);
        self::assertSame('2025-03-01T08:00:00+00:00', $result['createdAt']);
    }

    public function testSerializeWithNullNotePreservesNull(): void
    {
        $result = (new DocumentVersionSerializer($this->makeStubbedUrlGenerator()))->serialize($this->makeVersion(note: null));

        self::assertNull($result['note']);
    }

    public function testSerializeContainsExactlyExpectedKeys(): void
    {
        $result = (new DocumentVersionSerializer($this->makeStubbedUrlGenerator()))->serialize($this->makeVersion());

        self::assertSame(
            ['id', 'versionNumber', 'fileName', 'fileUrl', 'fileMime', 'fileSize', 'note', 'createdAt'],
            array_keys($result),
        );
    }
}
