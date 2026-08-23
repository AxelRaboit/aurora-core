<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\View;

use Aurora\Core\Storage\Enum\MimeGroupEnum;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializerInterface;
use Aurora\Module\Ged\DocumentFolder\Repository\DocumentFolderRepository;
use Aurora\Module\Ged\DocumentFolder\Serializer\DocumentFolderSerializerInterface;
use Aurora\Module\Ged\DocumentTag\Repository\DocumentTagRepository;
use Aurora\Module\Ged\DocumentTag\Serializer\DocumentTagSerializerInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class DocumentsViewBuilder
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentSerializerInterface $documentSerializer,
        private DocumentCategoryRepository $categoryRepository,
        private DocumentCategorySerializerInterface $categorySerializer,
        private DocumentTagRepository $tagRepository,
        private DocumentTagSerializerInterface $tagSerializer,
        private DocumentFolderRepository $folderRepository,
        private DocumentFolderSerializerInterface $folderSerializer,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function indexView(PaginationRequest $pagination): array
    {
        $categories = array_map(
            $this->categorySerializer->serialize(...),
            $this->categoryRepository->findAllOrdered(),
        );

        $tags = array_map(
            $this->tagSerializer->serialize(...),
            $this->tagRepository->findAllOrdered(),
        );

        $folders = $this->serializeFoldersWithCounts();

        return [
            'documents' => $this->buildListPayload($pagination),
            'categories' => $categories,
            'tags' => $tags,
            'folders' => $folders,
            'search' => $pagination->search ?? '',
            'createPath' => $this->urlGenerator->generate('backend_ged_documents_create'),
            'showPath' => $this->urlGenerator->generate('backend_ged_documents_show', ['id' => '__id__']),
            'versionsPath' => $this->urlGenerator->generate('backend_ged_documents_versions', ['id' => '__id__']),
            'usagePath' => $this->urlGenerator->generate('backend_ged_documents_usage', ['id' => '__id__']),
            'updatePath' => $this->urlGenerator->generate('backend_ged_documents_update', ['id' => '__id__']),
            'deletePath' => $this->urlGenerator->generate('backend_ged_documents_delete', ['id' => '__id__']),
            'cropPath' => $this->urlGenerator->generate('backend_ged_documents_crop', ['id' => '__id__']),
            'bulkDeletePath' => $this->urlGenerator->generate('backend_ged_documents_bulk_delete'),
            'listPath' => $this->urlGenerator->generate('backend_ged_documents_list'),
            // Media-style move endpoints (single + bulk) - power the sidebar
            // drag&drop and the bulk-move modal. The dedicated /backend/ged/folders
            // page remains untouched and continues to handle folder-tree management.
            'movePath' => $this->urlGenerator->generate('backend_ged_documents_move', ['id' => '__id__']),
            'bulkMovePath' => $this->urlGenerator->generate('backend_ged_documents_bulk_move'),
            // Sidebar folder CRUD reuses the existing /backend/ged/folders endpoints,
            // so create/edit/delete behave identically across both pages.
            'folderCreatePath' => $this->urlGenerator->generate('backend_ged_folders_create'),
            'folderEditPath' => $this->urlGenerator->generate('backend_ged_folders_update', ['id' => '__id__']),
            'folderDeletePath' => $this->urlGenerator->generate('backend_ged_folders_delete', ['id' => '__id__']),
            'folderMovePath' => $this->urlGenerator->generate('backend_ged_folders_move', ['id' => '__id__']),
            // GED-owned upload endpoint - no coupling to the Media library.
            // The form POSTs the file here, gets back the metadata, then
            // submits the regular JSON create/update with that metadata.
            'uploadPath' => $this->urlGenerator->generate('backend_ged_documents_upload'),
        ];
    }

    public function buildListPayload(
        PaginationRequest $pagination,
        ?int $categoryId = null,
        ?int $tagId = null,
        ?int $folderId = null,
        ?DocumentStatusEnum $status = null,
        ?MimeGroupEnum $mimeGroup = null,
        bool $rootOnly = false,
    ): array {
        $result = $this->documentRepository->findPaginated(
            $pagination->page,
            search: $pagination->search,
            categoryId: $categoryId,
            tagId: $tagId,
            folderId: $folderId,
            status: $status,
            mimeGroup: $mimeGroup,
            rootOnly: $rootOnly,
        );

        return [
            'success' => true,
            'items' => array_map($this->documentSerializer->serialize(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            // Sidebar refreshes counts on every navigation so the badges next
            // to folder names stay in sync after moves / deletes / uploads.
            'folders' => $this->serializeFoldersWithCounts(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeFoldersWithCounts(): array
    {
        $serializer = $this->folderSerializer->withDocumentCounts(
            $this->documentRepository->countGroupedByFolders(),
        );

        return array_map(
            $serializer->serialize(...),
            $this->folderRepository->findAllOrdered(),
        );
    }
}
