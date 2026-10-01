<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Editorial\Form\Repository\FormRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;
use Aurora\Module\Editorial\Post\Share\SiteUsefulLinks;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\PostType\Serializer\PostTypeSerializerInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Editorial\Taxonomy\Serializer\TaxonomySerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\Deck\Repository\DeckRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        private DeckRepository $deckRepository,
        private SiteUsefulLinks $siteUsefulLinks,
        private CustomerSpaceRepository $customerSpaceRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * Shared by the first render and by every later filter change, which
     * fetches this same payload as JSON - one shape, so the list cannot
     * drift between how it arrives and how it refreshes.
     *
     * @param list<int>    $postTypeIds
     * @param list<int>    $termIds
     * @param list<string> $statuses
     * @param list<string> $visibilities
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
        array $visibilities = [],
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
            visibilities: $visibilities,
        );

        $spaceNames = $this->customerSpaceNames($result['items']);

        return [
            'success' => true,
            'items' => array_map(
                fn (PostInterface $post): array => [
                    ...$this->postSerializer->serialize($post),
                    // Named in the row, so a client's audit reads as one
                    // among the site's pages without being opened.
                    'customerSpaceName' => $spaceNames[$post->getCustomerSpaceId() ?? 0] ?? null,
                ],
                $result['items'],
            ),
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
     * @param list<string>         $visibilities
     *
     * @return array<string, mixed>
     */
    public function indexView(
        array $listPayload,
        PaginationRequest $pagination,
        array $postTypeIds = [],
        array $termIds = [],
        array $statuses = [],
        array $visibilities = [],
    ): array {
        return [
            'posts' => $listPayload,
            'search' => $pagination->search ?? '',
            'postTypeIds' => $postTypeIds,
            'termIds' => $termIds,
            'statuses' => $statuses,
            'statusOptions' => PostStatusEnum::values(),
            'visibilities' => $visibilities,
            'visibilityOptions' => PostVisibilityEnum::values(),
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
            // Same reasoning as the forms above: only the edit screen has a
            // grid to place one in.
            'decks' => $this->deckChoices(),
            // What a page that follows the site's useful links will show, so
            // the editor can say so rather than show an empty list.
            'siteUsefulLinks' => $this->siteUsefulLinks->links(),
            // The client this document was written for, and the way back to
            // its space. Null for a page of the site.
            'customerSpace' => $post instanceof PostInterface ? $this->customerSpace($post) : null,
            ...$this->sharedContext(),
        ];
    }

    /** @return array{id: int, name: string, url: string}|null */
    private function customerSpace(PostInterface $post): ?array
    {
        $id = $post->getCustomerSpaceId();
        $space = null === $id ? null : $this->customerSpaceRepository->find($id);

        if (null === $space) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $space->getName(),
            'url' => $this->urlGenerator->generate('workspace_space_content', ['id' => $id, 'view' => 'publications']),
        ];
    }

    /**
     * The names of the spaces these publications belong to, in one query.
     *
     * @param list<PostInterface> $posts
     *
     * @return array<int, string>
     */
    private function customerSpaceNames(array $posts): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (PostInterface $post): ?int => $post->getCustomerSpaceId(),
            $posts,
        ))));

        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach ($this->customerSpaceRepository->findBy(['id' => $ids]) as $space) {
            $names[(int) $space->getId()] = $space->getName();
        }

        return $names;
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
    /**
     * The presentations a deck zone may show.
     *
     * Every deck, not only the shared ones: whether a link is live is answered
     * at render, and a list that silently omitted a deck would leave an author
     * hunting for one they can see in Studio. The panel says what happens when
     * there is no link.
     *
     * @return list<array{id: int, title: string}>
     */
    private function deckChoices(): array
    {
        $choices = [];

        foreach ($this->deckRepository->findBy([], ['title' => 'ASC']) as $deck) {
            $id = $deck->getId();

            if (null === $id) {
                continue;
            }

            $choices[] = ['id' => $id, 'title' => $deck->getTitle()];
        }

        return $choices;
    }

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
