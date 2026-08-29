<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentFolder\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Ged\DocumentFolder\Dto\DocumentFolderInputInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(DocumentFolderManagerInterface::class)]
class DocumentFolderManager implements DocumentFolderManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly DocumentFolderRepository $folderRepository,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(DocumentFolderInputInterface $input): DocumentFolderInterface
    {
        $folder = $this->createDocumentFolder();
        $this->applyInput($folder, $input);
        $this->entityManager->persist($folder);
        $this->entityManager->flush();

        $this->auditCreated($folder);

        return $folder;
    }

    public function update(DocumentFolderInterface $folder, DocumentFolderInputInterface $input): void
    {
        $this->applyInput($folder, $input);
        $this->entityManager->flush();

        $this->auditUpdated($folder);
    }

    public function delete(DocumentFolderInterface $folder): void
    {
        $this->auditDeleted($folder);

        $this->entityManager->remove($folder);
        $this->entityManager->flush();
    }

    /**
     * Moves a folder to a new parent (or root if null).
     * Automatically appends it at the end of the new parent's children.
     */
    public function move(DocumentFolderInterface $folder, ?DocumentFolderInterface $newParent): void
    {
        $folder->setParent($newParent);
        $this->entityManager->flush();
    }

    /**
     * Persists a new position order for a set of sibling folders.
     * Receives an ordered list of IDs; assigns position 0, 1, 2, ….
     *
     * @param list<int> $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        // One query for the whole list rather than one per row: reordering by
        // drag-and-drop sends every sibling each time, so the loop cost grew with
        // the folder count on a screen people use by dragging repeatedly.
        $folders = [];
        foreach ($this->folderRepository->findBy(['id' => $orderedIds]) as $folder) {
            $folders[(int) $folder->getId()] = $folder;
        }

        foreach ($orderedIds as $position => $folderId) {
            // An id that matched nothing is skipped, as before: a stale list from a
            // client that reordered against deleted rows should not fail the rest.
            ($folders[(int) $folderId] ?? null)?->setPosition($position);
        }

        $this->entityManager->flush();
    }

    protected function createDocumentFolder(): DocumentFolderInterface
    {
        return new DocumentFolder();
    }

    protected function applyInput(DocumentFolderInterface $folder, DocumentFolderInputInterface $input): void
    {
        $folder->setName($input->getName());
        $folder->setParent(null !== $input->getParentId() ? $this->folderRepository->find($input->getParentId()) : null);
    }

    protected function auditCreated(DocumentFolderInterface $folder): void
    {
        $this->auditLogger->log('ged', 'folder.created', 'DocumentFolder', $folder->getId(), $this->auditPayload($folder));
    }

    protected function auditUpdated(DocumentFolderInterface $folder): void
    {
        $this->auditLogger->log('ged', 'folder.updated', 'DocumentFolder', $folder->getId(), $this->auditPayload($folder));
    }

    protected function auditDeleted(DocumentFolderInterface $folder): void
    {
        $this->auditLogger->log('ged', 'folder.deleted', 'DocumentFolder', $folder->getId(), $this->auditPayload($folder));
    }

    protected function auditPayload(DocumentFolderInterface $folder): array
    {
        return ['name' => $folder->getName(), 'parentId' => $folder->getParent()?->getId()];
    }
}
