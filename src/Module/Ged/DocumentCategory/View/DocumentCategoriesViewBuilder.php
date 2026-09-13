<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DocumentCategory\View;

use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Ged\DocumentCategory\Repository\DocumentCategoryRepository;
use Aurora\Module\Ged\DocumentCategory\Serializer\DocumentCategorySerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class DocumentCategoriesViewBuilder
{
    public function __construct(
        private DocumentCategoryRepository $categoryRepository,
        private DocumentCategorySerializerInterface $categorySerializer,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function indexView(PaginationRequest $pagination): array
    {
        return [
            'categories' => $this->buildListPayload($pagination),
            'search' => $pagination->search ?? '',
            'createPath' => $this->urlGenerator->generate('backend_ged_categories_create'),
            'updatePath' => $this->urlGenerator->generate('backend_ged_categories_update', ['id' => '__id__']),
            'deletePath' => $this->urlGenerator->generate('backend_ged_categories_delete', ['id' => '__id__']),
            'restorePath' => $this->urlGenerator->generate('backend_ged_categories_restore', ['id' => '__id__']),
            'forceDeletePath' => $this->urlGenerator->generate('backend_ged_categories_force_delete', ['id' => '__id__']),
            'emptyTrashPath' => $this->urlGenerator->generate('backend_ged_categories_empty_trash'),
            'listPath' => $this->urlGenerator->generate('backend_ged_categories_list'),
        ];
    }

    public function buildListPayload(PaginationRequest $pagination, bool $trashed = false): array
    {
        $result = $this->categoryRepository->findPaginated($pagination->page, search: $pagination->search, trashed: $trashed);

        return [
            'success' => true,
            'items' => array_map($this->categorySerializer->serialize(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            // Sent on every page so the screen knows whether to offer the
            // trash at all, and what number to put on it.
        ];
    }
}
