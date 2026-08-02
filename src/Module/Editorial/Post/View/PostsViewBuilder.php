<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\PostType\Serializer\PostTypeSerializerInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Editorial\Taxonomy\Serializer\TaxonomySerializerInterface;

/**
 * Builds the payloads for the posts list and the standalone editor page.
 */
final readonly class PostsViewBuilder
{
    private const int PER_PAGE = 10;

    public function __construct(
        private PostRepository $postRepository,
        private PostTypeRepository $postTypeRepository,
        private TaxonomyRepository $taxonomyRepository,
        private PostSerializerInterface $postSerializer,
        private PostTypeSerializerInterface $postTypeSerializer,
        private TaxonomySerializerInterface $taxonomySerializer,
        private LocaleContextInterface $localeContext,
    ) {}

    /**
     * Shared by the first render and by every later filter change, which
     * fetches this same payload as JSON — one shape, so the list cannot
     * drift between how it arrives and how it refreshes.
     *
     * @param list<int>    $postTypeIds
     * @param list<int>    $termIds
     * @param list<string> $statuses
     *
     * @return array<string, mixed>
     */
    public function buildListPayload(
        PaginationRequest $pagination,
        array $postTypeIds = [],
        bool $trashed = false,
        ?int $authorId = null,
        array $termIds = [],
        array $statuses = [],
    ): array {
        $result = $this->postRepository->findPaginated(
            page: $pagination->page,
            locale: $this->localeContext->getDefaultLocale(),
            limit: self::PER_PAGE,
            search: $pagination->search,
            postTypeIds: $postTypeIds,
            trashed: $trashed,
            authorId: $authorId,
            termIds: $termIds,
            statuses: $statuses,
        );

        return [
            'success' => true,
            'items' => array_map($this->postSerializer->serialize(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ];
    }

    /**
     * @param array<string, mixed> $listPayload
     * @param list<int>            $postTypeIds
     * @param list<int>            $termIds
     * @param list<string>         $statuses
     *
     * @return array<string, mixed>
     */
    public function indexView(
        array $listPayload,
        PaginationRequest $pagination,
        bool $trashed,
        array $postTypeIds = [],
        array $termIds = [],
        array $statuses = [],
    ): array {
        return [
            'posts' => $listPayload,
            'search' => $pagination->search ?? '',
            'trashed' => $trashed,
            'postTypeIds' => $postTypeIds,
            'termIds' => $termIds,
            'statuses' => $statuses,
            'statusOptions' => PostStatusEnum::values(),
            ...$this->sharedContext(),
        ];
    }

    /**
     * The editor is a page of its own rather than a modal — a post is too
     * big for one. Null means create mode; the front swaps the URL for
     * /{id}/edit once the first save comes back.
     *
     * @return array<string, mixed>
     */
    public function editView(?PostInterface $post = null): array
    {
        return [
            'post' => $post instanceof PostInterface ? $this->postSerializer->serializeFull($post) : null,
            'statusOptions' => PostStatusEnum::values(),
            ...$this->sharedContext(),
        ];
    }

    /**
     * What both screens need to name things: the types a post can be, the
     * taxonomies it can be filed under, and the locales it is written in.
     *
     * @return array<string, mixed>
     */
    private function sharedContext(): array
    {
        return [
            'postTypes' => array_map(
                $this->postTypeSerializer->serialize(...),
                $this->postTypeRepository->findAllWithRelations(),
            ),
            'taxonomies' => array_map(
                $this->taxonomySerializer->serializeFull(...),
                $this->taxonomyRepository->findAllForIndex(),
            ),
            'locales' => $this->localeContext->getActiveLocales(),
        ];
    }
}
