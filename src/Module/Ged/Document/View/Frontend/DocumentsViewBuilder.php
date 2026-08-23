<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\View\Frontend;

use Aurora\Core\Frontend\View\ViewBuilder;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;

/**
 * Builds Twig and JSON payloads for the GED public library.
 * Centralises query + serialisation so the controller stays focused on HTTP flow.
 */
final readonly class DocumentsViewBuilder
{
    private const int PAGE_SIZE = 20;

    public function __construct(
        private DocumentRepository $documentRepository,
        private DocumentSerializerInterface $documentSerializer,
        private ViewBuilder $baseViewBuilder,
    ) {}

    /**
     * Full Twig payload for the index page (first render, SSR).
     *
     * @return array<string, mixed>
     */
    public function indexView(string $locale, int $page, string $searchPath): array
    {
        $result = $this->pageData($page, null);

        return array_merge($this->baseViewBuilder->baseView($locale), [
            'initialItems' => $result['items'],
            'initialPage' => $result['page'],
            'initialTotalPages' => $result['totalPages'],
            'initialTotal' => $result['total'],
            'searchPath' => $searchPath,
        ]);
    }

    /**
     * Paginated + optionally filtered document list - used by both indexView and the JSON search endpoint.
     *
     * @return array{items: array<mixed>, page: int, totalPages: int, total: int}
     */
    public function pageData(int $page, ?string $search): array
    {
        $result = $this->documentRepository->findPaginated(
            $page,
            self::PAGE_SIZE,
            search: $search,
            status: DocumentStatusEnum::Published,
        );

        return [
            'items' => array_map($this->documentSerializer->serialize(...), $result['items']),
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            'total' => $result['total'],
        ];
    }
}
