<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Serializer\PostSerializerInterface;

use function array_map;
use function is_array;
use function is_int;
use function is_string;

/**
 * The payloads the two gallery screens need.
 *
 * Leans on {@see PostsViewBuilder} for the list rather than querying again: it is
 * the same publications, paginated and searched the same way, and a second
 * implementation would drift in what "trashed" or "per page" mean. What differs is
 * only what the rows are *for*, and that is a route template in the template.
 *
 * The editor's payload is deliberately not `editView()`. That one sends post
 * types, taxonomies and status options, none of which this screen may change -
 * shipping them would be handing the browser a list of things it is not allowed to
 * touch, and the first person to wire a select to it would not know why the save
 * ignores them.
 */
final readonly class PostGalleriesViewBuilder
{
    public function __construct(
        private PostsViewBuilder $postsViewBuilder,
        private PostSerializerInterface $postSerializer,
        private LocaleContextInterface $localeContext,
        private PostRepository $postRepository,
    ) {}

    /**
     * @param array<string, mixed> $listPayload
     *
     * @return array<string, mixed>
     */
    public function indexView(array $listPayload, PaginationRequest $pagination): array
    {
        return [
            'posts' => $listPayload,
            'search' => $pagination->search ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function editView(PostInterface $post): array
    {
        $full = $this->postSerializer->serializeFull($post);

        /** @var array<string, array<string, mixed>> $translations */
        $translations = is_array($full['translations'] ?? null) ? $full['translations'] : [];

        // Just the captions, pulled out of the translations. The full editor keeps
        // whole translations because it edits titles and SEO in each language; this
        // screen edits the words on pictures, so shipping the rest would be sending
        // the browser fields the save will refuse.
        $words = [];

        foreach ($translations as $locale => $translation) {
            $words[$locale] = is_array($translation['gallery'] ?? null) ? $translation['gallery'] : [];
        }

        return [
            'post' => [
                'id' => $full['id'] ?? null,
                'galleryLayout' => $full['galleryLayout'] ?? [],
                'gallery' => $words,
            ],
            // The publication's name, so the screen can say which one is open. Read
            // off the default locale's translation: this screen never switches the
            // title, only the captions under it.
            'title' => $this->titleOf($translations),
            'locales' => $this->localeContext->getActiveLocales(),
        ];
    }

    /**
     * The publication's name in the default locale, or the first one that has one.
     *
     * A fallback rather than an empty heading: a draft translated only into English
     * on a French-default site still has a name, and "" tells the reader nothing
     * about which gallery they opened.
     *
     * @param array<string, array<string, mixed>> $translations
     */
    private function titleOf(array $translations): string
    {
        $default = $this->localeContext->getDefaultLocale();
        $candidates = [$translations[$default] ?? null, ...array_values($translations)];

        foreach ($candidates as $translation) {
            $title = is_array($translation) ? ($translation['title'] ?? null) : null;

            if (is_string($title) && '' !== $title) {
                return $title;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function listPayload(PaginationRequest $pagination): array
    {
        // No author scoping, unlike the posts list. Somebody brought in for the
        // pictures did not write the article, so restricting them to their own
        // would leave the screen empty for exactly the person it is built for.
        $payload = $this->postsViewBuilder->buildListPayload($pagination);

        /** @var list<array<string, mixed>> $items */
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $ids = [];

        foreach ($items as $item) {
            if (is_int($item['id'] ?? null)) {
                $ids[] = $item['id'];
            }
        }

        // The one thing this list needs that the posts list does not: whether a
        // publication still wants photographs. Without it every row looks the same
        // and the only way to find the empty ones is to open them, which is the
        // work this screen exists to save.
        $counts = $this->postRepository->galleryItemCounts($ids);

        $payload['items'] = array_map(
            static fn (array $item): array => [
                ...$item,
                'galleryItemCount' => $counts[$item['id'] ?? 0] ?? 0,
            ],
            $items,
        );

        return $payload;
    }
}
