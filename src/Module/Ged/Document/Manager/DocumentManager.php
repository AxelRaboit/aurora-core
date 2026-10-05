<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Manager;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Service\ImageRenditionGenerator;
use Aurora\Core\Storage\Service\PhotoExifReader;
use Aurora\Core\Storage\StorageManager;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\Document\Dto\DocumentInputInterface;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Document\Entity\DocumentVersionInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Repository\DocumentVersionRepository;
use Aurora\Module\Ged\Document\Service\GedDocumentUploader;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTagInterface;
use Aurora\Module\Ged\DocumentTag\Repository\DocumentTagRepository;
use Aurora\Module\Ged\Setting\GedSettingEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentManagerInterface::class)]
class DocumentManager implements DocumentManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly DocumentCategoryRepository $categoryRepository,
        protected readonly SequenceGenerator $sequenceGenerator,
        protected readonly SettingRepository $settingRepository,
        protected readonly AuditLogger $auditLogger,
        protected readonly DocumentTagRepository $tagRepository,
        protected readonly DocumentFolderRepository $folderRepository,
        protected readonly DocumentVersionRepository $versionRepository,
        protected readonly DocumentRepository $documentRepository,
        protected readonly GedDocumentUploader $uploader,
        protected readonly ImageRenditionGenerator $renditionGenerator,
        protected readonly StorageManager $storageManager,
        // Last and optional, so a project that builds this manager by hand
        // keeps working: without it, photographs simply carry no settings.
        protected readonly ?PhotoExifReader $exifReader = null,
    ) {}

    public function create(DocumentInputInterface $input): DocumentInterface
    {
        $document = $this->createDocument();
        $prefix = $this->settingRepository->getOrDefault(GedSettingEnum::DocumentPrefix);
        $document->setReference($this->sequenceGenerator->next($prefix));
        $this->applyInput($document, $input);
        // The uploader wrote these bytes moments ago, so they are wherever the
        // active disk is. Stamped before the renditions, which are generated
        // alongside the source and therefore land on the same suite.
        $document->setStorageDisk($this->storageManager->activeDisk());
        $this->regenerateRenditionsIfImage($document);
        $this->entityManager->persist($document);

        // The first version goes in with the document, in the same flush. A
        // document born a moment ago has no history, so its version is the
        // first and there is nothing to prune: asking the database for either,
        // then flushing again, cost two queries and a flush per file of an
        // import.
        if (null !== $document->getFilePath()) {
            $this->entityManager->persist($this->versionOf($document, 1));
        }

        $this->entityManager->flush();

        $this->auditCreated($document);

        return $document;
    }

    public function update(DocumentInterface $document, DocumentInputInterface $input): void
    {
        $newFilePath = $input->getFilePath();
        $currentFilePath = $document->getFilePath();
        // File "changed" when the incoming path is non-null AND differs from
        // the current one. The upload endpoint always returns a fresh
        // relative path on a new upload (timestamped + unique slug), so a
        // string compare is enough to detect a swap.
        $fileChanged = null !== $newFilePath && $newFilePath !== $currentFilePath;
        $previousRenditions = $document->getRenditions();

        $this->applyInput($document, $input);

        if ($fileChanged) {
            // The bytes were just written by the uploader, so they are on
            // whichever disk is active now - not necessarily the one the
            // previous file sat on.
            $previousDisk = $document->getStorageDisk();
            $document->setStorageDisk($this->storageManager->activeDisk());

            // Old renditions belong to the old file, and possibly to the old
            // suite. Dropped through that one rather than the active one.
            $this->renditionGenerator->deleteRenditions(
                $this->storageManager->forDisk($previousDisk),
                $previousRenditions,
            );
            $this->regenerateRenditionsIfImage($document);
        }

        $this->entityManager->flush();

        if ($fileChanged) {
            $this->recordVersion($document);
        }

        $this->auditUpdated($document);
    }

    /**
     * Moves a document to the trash.
     *
     * The row stays, and so do the bytes: this is the gesture somebody makes
     * by mistake, on a file that may exist nowhere else. What actually frees
     * the disk is {@see forceDelete()}, reached from the trash or by the
     * purge once the retention window has passed.
     */
    public function delete(DocumentInterface $document): void
    {
        if ($document->isTrashed()) {
            return;
        }

        $document->setDeletedAt(new DateTimeImmutable())->setTrashedWithFolderId(null);
        $this->entityManager->flush();

        $this->auditTrashed($document);
    }

    public function restore(DocumentInterface $document): void
    {
        if (!$document->isTrashed()) {
            return;
        }

        $document->setDeletedAt(null)->setTrashedWithFolderId(null);
        $this->entityManager->flush();

        $this->auditRestored($document);
    }

    /**
     * Deletes a document for good, bytes included.
     *
     * The renditions go through the disk that holds them, and the original file
     * only if no other row still points at it: a document can share its file
     * with another after a copy, and one deletion must not blank the other.
     */
    public function forceDelete(DocumentInterface $document): void
    {
        $this->auditDeleted($document);

        $owned = $this->collectOwnedFiles([$document]);
        $renditions = $document->getRenditions();
        $disk = $document->getStorageDisk();

        $this->entityManager->remove($document);
        $this->entityManager->flush();

        $this->renditionGenerator->deleteRenditions($this->storageManager->forDisk($disk), $renditions);
        $this->deleteUnreferencedFiles($owned, $disk);
    }

    public function emptyTrash(): int
    {
        return $this->destroy($this->documentRepository->findAllTrashed());
    }

    public function purgeTrashedBefore(DateTimeImmutable $cutoff): int
    {
        return $this->destroy($this->documentRepository->findTrashedBefore($cutoff));
    }

    public function move(DocumentInterface $document, ?DocumentFolderInterface $folder): void
    {
        $document->setFolder($folder);
        $this->entityManager->flush();
        $this->auditMoved($document, $folder);
    }

    public function bulkMove(array $ids, ?DocumentFolderInterface $folder): void
    {
        if ([] === $ids) {
            return;
        }

        $documents = $this->documentRepository->findBy(['id' => $ids]);
        foreach ($documents as $document) {
            $document->setFolder($folder);
        }

        $this->entityManager->flush();

        $this->auditLogger->logMany('ged', 'document.moved', 'Document', $this->auditEntries($documents, ['folder' => $folder?->getName()]));
    }

    /**
     * One flush for the whole selection, and an audit line per document that
     * changed: one already in that category is left alone and not logged, so
     * the log says what was filed rather than what was clicked.
     *
     * @param list<int> $ids
     */
    public function bulkCategorize(array $ids, ?DocumentCategoryInterface $category): int
    {
        if ([] === $ids) {
            return 0;
        }

        $changed = [];
        foreach ($this->documentRepository->findBy(['id' => $ids]) as $document) {
            if ($document->getCategory()?->getId() === $category?->getId()) {
                continue;
            }

            $document->setCategory($category);
            $changed[] = $document;
        }

        if ([] === $changed) {
            return 0;
        }

        $this->entityManager->flush();
        $this->auditLogger->logMany('ged', 'document.categorized', 'Document', $this->auditEntries($changed, ['category' => $category?->getName()]));

        return count($changed);
    }

    /**
     * Bulk-delete by id with a single flush. Audits each removal so the log
     * stays granular. Returns the number of rows actually removed.
     *
     * @param list<int> $ids
     */
    public function bulkDelete(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $documents = $this->documentRepository->findBy(['id' => $ids]);
        $trashed = [];

        foreach ($documents as $document) {
            if ($document->isTrashed()) {
                continue;
            }

            $document->setDeletedAt(new DateTimeImmutable())->setTrashedWithFolderId(null);
            $trashed[] = $document;
        }

        $this->entityManager->flush();

        // The ones this call trashed, not the whole selection: a document
        // already in the trash was logged when it went there.
        $this->auditLogger->logMany('ged', 'document.trashed', 'Document', $this->auditEntries($trashed));

        return count($trashed);
    }

    public function bulkRestore(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        $documents = $this->documentRepository->findBy(['id' => $ids]);
        $restored = [];

        foreach ($documents as $document) {
            if (!$document->isTrashed()) {
                continue;
            }

            $document->setDeletedAt(null)->setTrashedWithFolderId(null);
            $restored[] = $document;
        }

        $this->entityManager->flush();

        $this->auditLogger->logMany('ged', 'document.restored', 'Document', $this->auditEntries($restored));

        return count($restored);
    }

    /**
     * Destroys a set of documents, rows and bytes.
     *
     * Grouped by disk: a selection can span both backends, and each file has
     * to be removed through the one that actually holds it. Shared by the
     * trash's own buttons and by the purge, so the two can never drift into
     * deleting different things.
     *
     * @param list<Document> $documents
     */
    protected function destroy(array $documents): int
    {
        if ([] === $documents) {
            return 0;
        }

        $renditionsByDisk = [];
        $pathsByDisk = [];

        // Deux passes, et c'est tout l'objet de la correction. `auditDeleted()`
        // écrit une ligne et flush ; en une seule boucle, ce flush tombait
        // alors qu'un document précédent était déjà marqué pour suppression.
        // Le document quittait l'unité de travail, ses lignes de version -
        // chargées une ligne plus haut pour lire leurs chemins - y restaient
        // en pointant sur lui, et le flush final s'arrêtait sur « a new entity
        // was found through the relationship DocumentVersion#document ».
        //
        // D'où une panne qui ne ressemblait à rien : vider une corbeille
        // marchait, sauf si elle contenait un document ayant des versions, et
        // alors elle ne marchait plus jamais. Rien ne partait, l'écran
        // affichait « une erreur est survenue », et la suppression unitaire -
        // un seul document, donc pas de second audit au milieu - continuait
        // de passer.
        //
        // Les lignes d'audit partent désormais toutes en un flush, avant la
        // première suppression, ce qui garde la même garantie.
        $this->auditLogger->logMany('ged', 'document.deleted', 'Document', $this->auditEntries($documents));

        $documentsByDisk = [];

        foreach ($documents as $document) {
            $disk = $document->getStorageDisk()->value;
            $documentsByDisk[$disk][] = $document;
            $renditionsByDisk[$disk] = array_merge($renditionsByDisk[$disk] ?? [], $document->getRenditions());
        }

        // One query per disk for the versions, not one per document.
        foreach ($documentsByDisk as $disk => $onDisk) {
            $pathsByDisk[$disk] = $this->collectOwnedFiles($onDisk);
        }

        foreach ($documents as $document) {
            $this->entityManager->remove($document);
        }

        $this->entityManager->flush();

        foreach ($renditionsByDisk as $disk => $renditions) {
            $this->renditionGenerator->deleteRenditions(
                $this->storageManager->forDisk(StorageDiskEnum::from($disk)),
                $renditions,
            );
        }

        foreach ($pathsByDisk as $disk => $paths) {
            $this->deleteUnreferencedFiles($paths, StorageDiskEnum::from($disk));
        }

        return count($documents);
    }

    public function cropImage(DocumentInterface $document, int $x, int $y, int $width, int $height): void
    {
        $mime = MimeTypeEnum::tryFrom($document->getMimeType() ?? '');
        $filePath = $document->getFilePath();
        if (!$mime?->isRasterImage() || null === $filePath) {
            return;
        }

        // Crop writes to a fresh path, leaving the source bytes (referenced by
        // the prior version row) intact. Mirrors update(): mutate to the new
        // file, flush, then record it as the new current version.
        $result = $this->uploader->cropToNewFile(
            $filePath,
            $mime->value,
            $document->getOriginalName() ?? (string) $document->getFileName(),
            $x,
            $y,
            $width,
            $height,
        );

        if (null === $result) {
            return;
        }

        $previousRenditions = $document->getRenditions();

        $document->setFilePath($result['filePath']);
        $document->setFileName($result['fileName']);
        $document->setSize($result['size']);
        $document->setWidth($result['width']);
        $document->setHeight($result['height']);
        // Native images carry no separate thumbnail - the serializer falls
        // back to the file itself, so a stale PDF-style thumbnail must clear.
        $document->setThumbnailPath(null);

        // The crop wrote to the active disk, so the document moves with it.
        $previousDisk = $document->getStorageDisk();
        $document->setStorageDisk($this->storageManager->activeDisk());

        // Old renditions point at the pre-crop file path, on the disk that held
        // it - drop them there, then regenerate so srcset consumers stay in
        // sync.
        $this->renditionGenerator->deleteRenditions($this->storageManager->forDisk($previousDisk), $previousRenditions);
        $this->regenerateRenditionsIfImage($document);

        $this->entityManager->flush();
        $this->recordVersion($document);
        $this->auditCropped($document);
    }

    /**
     * Every relative path the given documents own on disk: their live file,
     * their generated thumbnail, and the file of each version row.
     *
     * Must be called *before* the rows are removed. Version rows disappear
     * through an `ON DELETE CASCADE` at the database level, so once the
     * document is gone there is nothing left to read their paths from - the
     * bytes would stay on disk with no row to ever name them again.
     *
     * @param list<DocumentInterface> $documents
     *
     * @return list<string>
     */
    protected function collectOwnedFiles(array $documents): array
    {
        $paths = [];
        $versionPaths = $this->versionRepository->findFilePathsByDocument($documents);

        foreach ($documents as $document) {
            foreach ([$document->getFilePath(), $document->getThumbnailPath(), ...$versionPaths[(int) $document->getId()] ?? []] as $path) {
                if (null !== $path && '' !== $path) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * Erases the given files, minus any path a surviving row still points at.
     *
     * The check is not paranoia: `recordVersion()` deliberately makes a
     * version row share the live document's `filePath`, so a naive delete
     * would take a file another row still owns. Call this *after* the flush -
     * the rows being deleted must already be gone for the query to answer
     * about survivors only, and a failed flush must not cost anyone their
     * bytes.
     *
     * @param list<string> $paths
     */
    protected function deleteUnreferencedFiles(array $paths, StorageDiskEnum $disk): void
    {
        if ([] === $paths) {
            return;
        }

        $stillInUse = array_merge(
            $this->documentRepository->filterPathsInUse($paths),
            $this->versionRepository->filterPathsInUse($paths),
        );

        $adapter = $this->storageManager->forDisk($disk);

        foreach (array_diff($paths, $stillInUse) as $path) {
            $adapter->delete($path);
        }
    }

    protected function createDocument(): DocumentInterface
    {
        return new Document();
    }

    protected function createDocumentVersion(): DocumentVersionInterface
    {
        return new DocumentVersion();
    }

    /**
     * Snapshots the current physical file metadata onto a new version row.
     * The file itself is not duplicated on disk - both the live document and
     * the historical version row point at the same `filePath`. If the doc's
     * file is later swapped, the old version row still references the prior
     * path (which the upload endpoint keeps untouched, since it writes new
     * paths per upload).
     */
    protected function recordVersion(DocumentInterface $document): void
    {
        $this->entityManager->persist($this->versionOf($document, $this->versionRepository->getNextVersionNumber($document)));
        $this->entityManager->flush();

        $this->pruneVersions($document);
    }

    /** A version row pointing at the document's current file. */
    protected function versionOf(DocumentInterface $document, int $number): DocumentVersionInterface
    {
        $version = $this->createDocumentVersion();
        $version->setDocument($document)
            ->setFilePath((string) $document->getFilePath())
            ->setFileName((string) $document->getFileName())
            ->setOriginalName((string) $document->getOriginalName())
            ->setMimeType((string) $document->getMimeType())
            ->setSize((int) $document->getSize())
            // The version points at the document's current file, so it points
            // at the same suite. They diverge later, when the document moves
            // and its history stays where it was.
            ->setStorageDisk($document->getStorageDisk())
            ->setVersionNumber($number);

        return $version;
    }

    /**
     * Keeps at most `file_versions_limit` versions per document (rolling
     * window): older versions are removed along with their physical files.
     * The current file is always the newest version, so it is never pruned.
     */
    protected function pruneVersions(DocumentInterface $document): void
    {
        $limit = (int) $this->settingRepository->get(
            ApplicationParameterEnum::FileVersionsLimit->value,
            ApplicationParameterEnum::FileVersionsLimit->getDefaultValue(),
        );

        $prunable = $this->versionRepository->findPrunable($document, $limit);
        if ([] === $prunable) {
            return;
        }

        $currentPath = $document->getFilePath();
        foreach ($prunable as $version) {
            if ($version->getFilePath() !== $currentPath) {
                // Through the version's own backend: a document moved after
                // this version was recorded left its history behind.
                $this->storageManager->forDisk($version->getStorageDisk())->delete($version->getFilePath());
            }

            $this->entityManager->remove($version);
        }

        $this->entityManager->flush();
    }

    protected function applyInput(DocumentInterface $document, DocumentInputInterface $input): void
    {
        $document->setTitle($input->getTitle());
        $document->setDescription($input->getDescription());
        $document->setStatus($input->getStatus());
        $document->setAlt($input->getAlt());
        $document->setCaption($input->getCaption());
        $document->setCategory(null !== $input->getCategoryId() ? $this->categoryRepository->find($input->getCategoryId()) : null);

        // File metadata is only overwritten when the input carries a fresh
        // upload (filePath set). An update without a new upload keeps the
        // existing file unchanged - null inputs are ignored.
        if (null !== $input->getFilePath()) {
            $document->setFilePath($input->getFilePath());
            $document->setFileName($input->getFileName());
            $document->setOriginalName($input->getOriginalName());
            $document->setMimeType($input->getMimeType());
            $document->setSize($input->getSize());
            $document->setWidth($input->getWidth());
            $document->setHeight($input->getHeight());
            $document->setThumbnailPath($input->getThumbnailPath());
        }

        // Same rule for the provenance, and for the same reason: an edit that
        // only renames a stock photo must not erase who took it, which would
        // leave the picture rendered without the credit it owes.
        if (null !== $input->getSourceUrl()) {
            $document->setSourceUrl($input->getSourceUrl());
            $document->setAttributionName($input->getAttributionName());
            $document->setAttributionUrl($input->getAttributionUrl());
        }

        $document->clearTags();
        foreach ($input->getTagIds() as $tagId) {
            $tag = $this->tagRepository->find($tagId);
            if ($tag instanceof DocumentTagInterface) {
                $document->addTag($tag);
            }
        }

        $document->setFolder(null !== $input->getFolderId() ? $this->folderRepository->find($input->getFolderId()) : null);

        $document->setFocalX($input->getFocalX());
        $document->setFocalY($input->getFocalY());

        $document->setKept($input->isKept());

        $original = null !== $input->getOriginalId() ? $this->documentRepository->find($input->getOriginalId()) : null;
        $document->setOriginal($original);
        $document->setAlternateLabel($original instanceof DocumentInterface ? $input->getAlternateLabel() : null);
    }

    /**
     * Regenerates the responsive renditions (thumbnail/medium/large in WebP)
     * for raster image documents. No-op for non-images, PDFs and missing
     * files. Called after every filePath swap (create / update / crop).
     *
     * **And re-reads the size afterwards.** The size on the record is the one
     * the upload measured, and for a JPEG that number stops being true one
     * line later: {@see ImageRenditionGenerator} re-encodes the source in place
     * at quality 85 and strips its metadata. Measured on a real import, a
     * 1,532,467 byte photograph is 213,901 on disk once filed - so the
     * library was showing a weight seven times the truth, and every quota or
     * total built on it was wrong by as much.
     *
     * Asked of the adapter rather than of the local file: the source may live
     * in object storage, where the only honest answer comes from a stat.
     */
    protected function regenerateRenditionsIfImage(DocumentInterface $document): void
    {
        $filePath = $document->getFilePath();
        if (null === $filePath) {
            $document->setRenditions([]);

            return;
        }

        $adapter = $this->storageManager->forDisk($document->getStorageDisk());

        // Before the renditions, which re-encode a JPEG and drop its metadata.
        // A file that says nothing - a crop of an already re-encoded source -
        // leaves the settings read from the original alone.
        $exif = $this->exifReader?->read($adapter, $filePath, (string) $document->getMimeType()) ?? [];

        if ([] !== $exif) {
            $document->setExif($exif);
        }

        $renditions = $this->renditionGenerator->generate(
            $adapter,
            $filePath,
            (string) $document->getMimeType(),
        );
        $document->setRenditions($renditions);

        $stored = $adapter->stat($filePath);

        if ($stored instanceof StoredObject && $stored->size > 0) {
            $document->setSize($stored->size);
        }
    }

    protected function auditCreated(DocumentInterface $document): void
    {
        $this->auditLogger->log('ged', 'document.created', 'Document', $document->getId(), $this->auditPayload($document));
    }

    protected function auditUpdated(DocumentInterface $document): void
    {
        $this->auditLogger->log('ged', 'document.updated', 'Document', $document->getId(), $this->auditPayload($document));
    }

    protected function auditTrashed(DocumentInterface $document): void
    {
        $this->auditLogger->log('ged', 'document.trashed', 'Document', $document->getId(), $this->auditPayload($document));
    }

    protected function auditRestored(DocumentInterface $document): void
    {
        $this->auditLogger->log('ged', 'document.restored', 'Document', $document->getId(), $this->auditPayload($document));
    }

    protected function auditDeleted(DocumentInterface $document): void
    {
        $this->auditLogger->log('ged', 'document.deleted', 'Document', $document->getId(), $this->auditPayload($document));
    }

    protected function auditCropped(DocumentInterface $document): void
    {
        $this->auditLogger->log('ged', 'document.cropped', 'Document', $document->getId(), $this->auditPayload($document));
    }

    protected function auditMoved(DocumentInterface $document, ?DocumentFolderInterface $folder): void
    {
        $this->auditLogger->log('ged', 'document.moved', 'Document', $document->getId(), [
            ...$this->auditPayload($document),
            'folder' => $folder?->getName(),
        ]);
    }

    /**
     * One audit line per document, for `AuditLogger::logMany()`.
     *
     * @param list<DocumentInterface> $documents
     * @param array<string, mixed>    $extra     added to every line
     *
     * @return list<array{id: int|null, data: array<string, mixed>}>
     */
    protected function auditEntries(array $documents, array $extra = []): array
    {
        return array_map(
            fn (DocumentInterface $document): array => ['id' => $document->getId(), 'data' => [...$this->auditPayload($document), ...$extra]],
            $documents,
        );
    }

    protected function auditPayload(DocumentInterface $document): array
    {
        return ['title' => $document->getTitle(), 'reference' => $document->getReference()];
    }
}
