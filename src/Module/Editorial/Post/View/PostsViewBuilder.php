<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Editorial\Form\Repository\FormRepository;
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
        private FormRepository $formRepository,
    ) {}

    /**
     * Shared by the first render and by every later filter change, which
     * fetches this same payload as JSON - one shape, so the list cannot
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
        array $postTypeIds = [],
        array $termIds = [],
        array $statuses = [],
    ): array {
        return [
            'posts' => $listPayload,
            'search' => $pagination->search ?? '',
            'postTypeIds' => $postTypeIds,
            'termIds' => $termIds,
            'statuses' => $statuses,
            'statusOptions' => PostStatusEnum::values(),
            ...$this->sharedContext(),
        ];
    }

    /**
     * The editor is a page of its own rather than a modal - a post is too
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
            // Only the edit screen names forms: the list screen has no grid
            // to pose one in.
            'forms' => $this->formChoices(),
            ...$this->sharedContext(),
        ];
    }

    /**
     * The forms a grid may pose, as a name and an id.
     *
     * Inactive ones are left out rather than offered and then refused at
     * render: a form the site has not published has no page, and a zone
     * should not be a way around that.
     *
     * The title comes from whichever translation has one - the dropdown only
     * has to let an author tell two forms apart, and a form written in one
     * language must still be nameable while editing another.
     *
     * @return list<array{id: int|null, title: string}>
     */
    private function formChoices(): array
    {
        $choices = [];

        foreach ($this->formRepository->findAllForIndex() as $form) {
            if (!$form->isActive()) {
                continue;
            }

            $title = null;
            foreach ($form->getTranslations() as $translation) {
                $candidate = mb_trim($translation->getTitle());
                if ('' !== $candidate) {
                    $title = $candidate;
                    break;
                }
            }

            $choices[] = [
                'id' => $form->getId(),
                'title' => $title ?? sprintf('#%d', (int) $form->getId()),
            ];
        }

        return $choices;
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
