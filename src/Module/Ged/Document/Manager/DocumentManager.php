<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Manager;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Service\ImageVariantGenerator;
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
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTagInterface;
use Aurora\Module\Ged\DocumentTag\Repository\DocumentTagRepository;
use Aurora\Module\Ged\Setting\GedSettingEnum;
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
        protected readonly ImageVariantGenerator $variantGenerator,
    ) {}

    public function create(DocumentInputInterface $input): DocumentInterface
    {
        $document = $this->createDocument();
        $prefix = $this->settingRepository->getOrDefault(GedSettingEnum::DocumentPrefix);
        $document->setReference($this->sequenceGenerator->next($prefix));
        $this->applyInput($document, $input);
        $this->regenerateVariantsIfImage($document);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        if (null !== $document->getFilePath()) {
            $this->recordVersion($document);
        }

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
        $previousVariants = $document->getVariants();

        $this->applyInput($document, $input);

        if ($fileChanged) {
            // Old variants are orphaned by the new file path - drop them on
            // disk before re-encoding the new source.
            $this->variantGenerator->deleteVariants($previousVariants);
            $this->regenerateVariantsIfImage($document);
        }

        $this->entityManager->flush();

        if ($fileChanged) {
            $this->recordVersion($document);
        }

        $this->auditUpdated($document);
    }

    public function delete(DocumentInterface $document): void
    {
        $this->auditDeleted($document);

        $owned = $this->collectOwnedFiles([$document]);
        $variants = $document->getVariants();

        $this->entityManager->remove($document);
        $this->entityManager->flush();

        $this->variantGenerator->deleteVariants($variants);
        $this->deleteUnreferencedFiles($owned);
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

        foreach ($documents as $document) {
            $this->auditMoved($document, $folder);
        }
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
        $owned = $this->collectOwnedFiles($documents);
        $variants = [];
        foreach ($documents as $document) {
            $this->auditDeleted($document);
            $variants = array_merge($variants, $document->getVariants());
            $this->entityManager->remove($document);
        }

        $this->entityManager->flush();

        $this->variantGenerator->deleteVariants($variants);
        $this->deleteUnreferencedFiles($owned);

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

        $previousVariants = $document->getVariants();

        $document->setFilePath($result['filePath']);
        $document->setFileName($result['fileName']);
        $document->setSize($result['size']);
        $document->setWidth($result['width']);
        $document->setHeight($result['height']);
        // Native images carry no separate thumbnail - the serializer falls
        // back to the file itself, so a stale PDF-style thumbnail must clear.
        $document->setThumbnailPath(null);

        // Old variants point at the pre-crop file path - drop them and
        // regenerate so srcset/object-fit consumers stay in sync.
        $this->variantGenerator->deleteVariants($previousVariants);
        $this->regenerateVariantsIfImage($document);

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
        foreach ($documents as $document) {
            foreach ([$document->getFilePath(), $document->getThumbnailPath()] as $path) {
                if (null !== $path && '' !== $path) {
                    $paths[$path] = true;
                }
            }

            foreach ($this->versionRepository->findByDocument($document) as $version) {
                $versionPath = $version->getFilePath();
                if ('' !== $versionPath) {
                    $paths[$versionPath] = true;
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
    protected function deleteUnreferencedFiles(array $paths): void
    {
        if ([] === $paths) {
            return;
        }

        $stillInUse = array_merge(
            $this->documentRepository->filterPathsInUse($paths),
            $this->versionRepository->filterPathsInUse($paths),
        );

        foreach (array_diff($paths, $stillInUse) as $path) {
            $this->uploader->deleteFile($path);
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
        $version = $this->createDocumentVersion();
        $version->setDocument($document)
            ->setFilePath((string) $document->getFilePath())
            ->setFileName((string) $document->getFileName())
            ->setOriginalName((string) $document->getOriginalName())
            ->setMimeType((string) $document->getMimeType())
            ->setSize((int) $document->getSize())
            ->setVersionNumber($this->versionRepository->getNextVersionNumber($document));
        $this->entityManager->persist($version);
        $this->entityManager->flush();

        $this->pruneVersions($document);
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
                $this->uploader->deleteFile($version->getFilePath());
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
    }

    /**
     * Regenerates the responsive variants (thumbnail/medium/large in WebP)
     * for raster image documents. No-op for non-images, PDFs and missing
     * files. Called after every filePath swap (create / update / crop).
     */
    protected function regenerateVariantsIfImage(DocumentInterface $document): void
    {
        $filePath = $document->getFilePath();
        if (null === $filePath) {
            $document->setVariants([]);

            return;
        }

        $variants = $this->variantGenerator->generate($filePath, (string) $document->getMimeType());
        $document->setVariants($variants);
    }

    protected function auditCreated(DocumentInterface $document): void
    {
        $this->auditLogger->log('ged', 'document.created', 'Document', $document->getId(), $this->auditPayload($document));
    }

    protected function auditUpdated(DocumentInterface $document): void
    {
        $this->auditLogger->log('ged', 'document.updated', 'Document', $document->getId(), $this->auditPayload($document));
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

    protected function auditPayload(DocumentInterface $document): array
    {
        return ['title' => $document->getTitle(), 'reference' => $document->getReference()];
    }
}
