<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Orphan;

use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\Orphan\ReferencedKeysProviderInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;

/**
 * Every key the document library still names.
 *
 * A document's own file, the still rendered from it, each responsive variant,
 * and the snapshot every previous version points at. Extracted from the
 * command that used to hold it when the sweep grew to cover more than one
 * area.
 */
final readonly class GedReferencedKeysProvider implements ReferencedKeysProviderInterface
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentVersionRepository $versionRepository,
    ) {}

    public function area(): StorageAreaEnum
    {
        return StorageAreaEnum::Ged;
    }

    public function referencedKeys(): iterable
    {
        /** @var list<array{filePath: string|null, thumbnailPath: string|null, variants: array<string, string>}> $documents */
        $documents = $this->documentRepository->createQueryBuilder('d')
            ->select('d.filePath', 'd.thumbnailPath', 'd.variants')
            ->getQuery()
            ->getResult();

        foreach ($documents as $document) {
            foreach ([$document['filePath'], $document['thumbnailPath']] as $path) {
                if (null !== $path && '' !== $path) {
                    yield $path;
                }
            }

            foreach ($document['variants'] as $variant) {
                if ('' !== $variant) {
                    yield $variant;
                }
            }
        }

        /** @var list<array{filePath: string}> $versions */
        $versions = $this->versionRepository->createQueryBuilder('v')
            ->select('v.filePath')
            ->getQuery()
            ->getResult();

        foreach ($versions as $version) {
            yield $version['filePath'];
        }
    }
}
