<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Service;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use Aurora\Core\Storage\StorageManager;
use Aurora\Core\Storage\Workspace\LocalWorkspace;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Entity\DocumentVersionInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use Aurora\Module\Ged\Enum\DocumentTransferStateEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function sprintf;

/**
 * Moves one document's bytes from one backend to the other.
 *
 * **The unit is the document, not the file.** Its own bytes, its thumbnail,
 * its three generated renditions, and the file of every version it kept. A
 * document with half of itself on each side would have a column that lies, and
 * nothing downstream is prepared for that.
 *
 * **The order is copy, verify, record, delete, and never another.** A process
 * killed at any point leaves the document intact and readable where it was;
 * what it leaves on the other side is at worst an unreferenced copy, which the
 * next attempt overwrites because the keys are identical on both sides. That
 * identity is also what makes a re-run after a failure safe to do without
 * thinking.
 *
 * **Deleting at the source asks first.** Two documents can point at the same
 * path, and a version deliberately shares the live document's file, so the
 * source copy is only removed once no row left on that side still names it.
 * Note the question has to be asked per backend: the paths do not change when
 * a document moves, so the plain "is this in use" would answer yes about the
 * rows that have just arrived on the other side.
 */
final readonly class DocumentRelocator
{
    public function __construct(
        private StorageManager $storageManager,
        private LocalWorkspace $workspace,
        private EntityManagerInterface $entityManager,
        private DocumentRepository $documentRepository,
        private DocumentVersionRepository $versionRepository,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function relocate(DocumentInterface $document, StorageDiskEnum $target): DocumentRelocation
    {
        $source = $document->getStorageDisk();

        if ($source === $target) {
            return DocumentRelocation::alreadyThere($target);
        }

        if (!$this->claim($document)) {
            return DocumentRelocation::busy();
        }

        try {
            $relocation = $this->copyAndRecord($document, $source, $target);
            $this->release($document, null);

            return $relocation;
        } catch (Throwable $throwable) {
            $this->logger->error('Relocating document {id} to {target} failed: {reason}', [
                'id' => $document->getId(),
                'target' => $target->value,
                'reason' => $throwable->getMessage(),
            ]);
            $this->release($document, $throwable->getMessage());

            return DocumentRelocation::failed($throwable->getMessage());
        }
    }

    /**
     * How much this document weighs, so a caller can decide whether to do the
     * move inline or hand it to a worker.
     */
    public function weigh(DocumentInterface $document): int
    {
        $adapter = $this->storageManager->forDisk($document->getStorageDisk());
        $bytes = 0;

        foreach ($this->keysOf($document) as $key) {
            $stored = $adapter->stat($key);
            $bytes += $stored instanceof StoredObject ? $stored->size : 0;
        }

        return $bytes;
    }

    /**
     * Every key this document owns, deduplicated.
     *
     * Versions share the live file's path by design, so the same key can be
     * named several times; copying it twice would work and cost twice.
     *
     * @param list<DocumentVersionInterface>|null $versions the document's versions when the
     *                                                      caller has them already
     *
     * @return list<string>
     */
    public function keysOf(DocumentInterface $document, ?array $versions = null): array
    {
        $versions ??= $this->versionRepository->findByDocument($document);
        $keys = [];

        foreach ([$document->getFilePath(), $document->getThumbnailPath()] as $path) {
            if (null !== $path && '' !== $path) {
                $keys[$path] = true;
            }
        }

        foreach ($document->getRenditions() as $rendition) {
            if ('' !== $rendition) {
                $keys[$rendition] = true;
            }
        }

        foreach ($versions as $version) {
            if ('' !== $version->getFilePath()) {
                $keys[$version->getFilePath()] = true;
            }
        }

        return array_keys($keys);
    }

    private function copyAndRecord(
        DocumentInterface $document,
        StorageDiskEnum $source,
        StorageDiskEnum $target,
    ): DocumentRelocation {
        $from = $this->storageManager->forDisk($source);
        $to = $this->storageManager->forDisk($target);
        // Read once, for the keys to copy and for the rows to re-point after.
        $versions = $this->versionRepository->findByDocument($document);
        $keys = $this->keysOf($document, $versions);

        $moved = 0;
        $bytes = 0;

        foreach ($keys as $key) {
            if (!$from->exists($key)) {
                // A path a row names and no backend holds. Pre-existing damage
                // rather than something this move caused, so it is stepped over
                // rather than made fatal: refusing to move a document because
                // one of its old versions lost its file helps nobody.
                $this->logger->warning('Document {id}: no file at {key} on {disk}, skipped', [
                    'id' => $document->getId(),
                    'key' => $key,
                    'disk' => $source->value,
                ]);

                continue;
            }

            $bytes += $this->copy($from, $to, $key);
            ++$moved;
        }

        // Recorded only once every byte is on the other side and verified.
        $document->setStorageDisk($target);

        foreach ($versions as $version) {
            if ($version->getStorageDisk() === $source && in_array($version->getFilePath(), $keys, true)) {
                $version->setStorageDisk($target);
            }
        }

        $this->entityManager->flush();

        // Last, and only now: until the flush above, the rows still pointed at
        // the source, and deleting first would have made the document
        // unreadable for the width of a transaction.
        $this->deleteFromSource($from, $source, $keys);

        return DocumentRelocation::moved($target, $moved, $bytes);
    }

    /**
     * Copies one object across and checks it arrived whole.
     *
     * Through a local working copy rather than streaming one adapter into the
     * other: it is the one path that works for every pair of backends,
     * including local to local, and {@see LocalWorkspace} already owns the
     * temporary file and its removal.
     */
    private function copy(StorageAdapterInterface $from, StorageAdapterInterface $to, string $key): int
    {
        $expected = $from->stat($key)?->size;

        $this->workspace->readable($from, $key, static function (string $path) use ($to, $key): void {
            $to->writeFromLocalFile($key, $path);
        });

        $written = $to->stat($key)?->size;

        // Compared rather than trusted. A write that half succeeded is exactly
        // the case where deleting the source afterwards would be a data loss,
        // and the size is the cheapest thing that catches it.
        if (null !== $expected && $written !== $expected) {
            throw new StorageException(sprintf('Copy of "%s" arrived as %s bytes instead of %d.', $key, null === $written ? 'nothing' : (string) $written, $expected));
        }

        return $expected ?? 0;
    }

    /**
     * @param list<string> $keys
     */
    private function deleteFromSource(StorageAdapterInterface $from, StorageDiskEnum $source, array $keys): void
    {
        $stillThere = array_merge(
            $this->documentRepository->filterPathsInUseOnDisk($keys, $source),
            $this->versionRepository->filterPathsInUseOnDisk($keys, $source),
        );

        $removable = array_values(array_diff($keys, $stillThere));

        if ([] !== $removable) {
            $from->deleteMany($removable);
        }
    }

    /**
     * Takes the document, in one conditional write.
     *
     * A plain read-then-write would let two clicks both see `Idle` and both
     * proceed. This asks the database to make the change only if the state is
     * still what we read, and takes the row count as the answer.
     */
    private function claim(DocumentInterface $document): bool
    {
        $claimed = $this->entityManager->createQuery(
            'UPDATE '.$this->documentRepository->getClassName().' d
             SET d.storageTransferState = :pending, d.storageTransferError = NULL
             WHERE d.id = :id AND d.storageTransferState != :pending',
        )
            ->setParameter('pending', DocumentTransferStateEnum::Pending)
            ->setParameter('id', $document->getId())
            ->execute();

        if (1 !== $claimed) {
            return false;
        }

        // The UPDATE went round the identity map, so the object in memory still
        // believes what it believed.
        $document->setStorageTransferState(DocumentTransferStateEnum::Pending);
        $document->setStorageTransferError(null);

        return true;
    }

    private function release(DocumentInterface $document, ?string $error): void
    {
        $document->setStorageTransferState(
            null === $error ? DocumentTransferStateEnum::Idle : DocumentTransferStateEnum::Failed,
        );
        $document->setStorageTransferError($error);

        $this->entityManager->flush();
    }
}
