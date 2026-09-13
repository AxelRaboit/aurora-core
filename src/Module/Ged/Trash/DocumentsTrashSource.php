<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Trash;

use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSourceInterface;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;

final readonly class DocumentsTrashSource implements TrashSourceInterface
{
    public function __construct(private DocumentRepository $documentRepository) {}

    public function getModuleKey(): string
    {
        return 'ged';
    }

    public function getRequiredPrivilege(): string
    {
        return 'ged.documents.view';
    }

    public function getSummary(int $limit): TrashSummary
    {
        $page = $this->documentRepository->findPaginated(1, $limit, trashed: true);

        return new TrashSummary(
            key: 'ged_documents',
            labelKey: 'backend.nav.documents',
            icon: 'folder-open',
            count: $page['total'],
            items: array_map($this->present(...), $page['items']),
            oldestDeletedAt: $this->documentRepository->oldestTrashedAt(),
            restoreRoute: 'backend_ged_documents_restore',
            forceDeleteRoute: 'backend_ged_documents_force_delete',
            emptyTrashRoute: 'backend_ged_documents_empty_trash',
            actionPrivilege: 'ged.documents.delete',
        );
    }

    private function present(DocumentInterface $document): TrashItem
    {
        return new TrashItem(
            id: (int) $document->getId(),
            label: $document->getTitle(),
            deletedAt: $document->getDeletedAt(),
            // Where it will land again, which is the one thing a restore has
            // to be right about.
            context: $document->getFolder()?->getName(),
        );
    }
}
