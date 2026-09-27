<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use Aurora\Module\Editorial\GitHub\Service\GitHubActivityView;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Post\Service\ThumbnailPresenter;
use Collator;
use DateTimeImmutable;
use IntlDateFormatter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The zones that list publications: a list, an A to Z index, an activity
 * feed, and the card each of them draws a publication as.
 *
 * Split off {@see GridViewBuilder}, which orchestrates; each list asks for
 * its publications in one query and warms their cards in another.
 */
final readonly class ZoneListingViews
{
    /** How many publications an A to Z index lists at most. */
    private const int INDEX_LIMIT = 300;

    public function __construct(
        private PostRepository $postRepository,
        private UrlGeneratorInterface $urlGenerator,
        private GitHubActivityView $gitHubActivityView,
        private ThumbnailPresenter $thumbnailPresenter,
    ) {}

    /**
     * A list zone, answered from the database on every render.
     *
     * One query per zone. A page holding three of them makes three, which is
     * the price of a list that is never out of date - the alternative is an
     * author remembering to edit a page every time they publish.
     *
     * @return array{columns: int, variant: string, cards: list<array<string, mixed>>}
     */
    public function postListView(array $zone, string $locale, ?int $currentPostId): array
    {
        if ('index' === ($zone['options']['listLayout'] ?? 'cards')) {
            return $this->postIndexView($zone, $locale, $currentPostId);
        }

        $posts = $this->postRepository->findLatestPublished(
            $locale,
            (int) $zone['limit'],
            $zone['postTypeId'],
            $zone['termId'],
            // A publication listing its neighbours should not offer itself
            // among them.
            $currentPostId,
        );
        $this->postRepository->warmCards($posts);

        $cards = [];
        foreach ($posts as $post) {
            $card = $this->postCard($post, $locale);
            if (null !== $card) {
                $cards[] = $card;
            }
        }

        return [
            'columns' => (int) $zone['columns'],
            'variant' => (string) $zone['cardVariant'],
            'cards' => $cards,
        ];
    }

    /**
     * Every publication the list would show, by letter, for an index.
     *
     * The count is not the zone's `limit`: an index that stops at twelve is
     * not an index. It stops at INDEX_LIMIT instead, which is a documentation
     * or a glossary of a good size, and past which a page of links wants a
     * search rather than a longer page.
     *
     * Letters are read off the title with its accents removed, so « Écran »
     * files under E; anything that does not start with a letter files under #.
     *
     * @param array<string, mixed> $zone
     *
     * @return array{columns: int, variant: string, cards: list<array<string, mixed>>, index: list<array{letter: string, entries: list<array{title: string, url: string}>}>}
     */
    private function postIndexView(array $zone, string $locale, ?int $currentPostId): array
    {
        $posts = $this->postRepository->findLatestPublished($locale, self::INDEX_LIMIT, $zone['postTypeId'], $zone['termId'], $currentPostId);
        $this->postRepository->warmCards($posts);
        $collator = new Collator($locale);
        $entries = [];

        foreach ($posts as $post) {
            $card = $this->postCard($post, $locale);

            if (null !== $card && '' !== (string) $card['title']) {
                $entries[] = [
                    'title' => (string) $card['title'],
                    'url' => $this->urlGenerator->generate('editorial_post', [
                        'locale' => $locale,
                        'postTypeSlug' => $card['postTypeSlug'],
                        'slug' => $card['slug'],
                    ]),
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => (int) $collator->compare($a['title'], $b['title']));

        $groups = [];
        foreach ($entries as $entry) {
            $first = mb_strtoupper(mb_substr((string) transliterator_transliterate('Any-Latin; Latin-ASCII', $entry['title']), 0, 1));
            $letter = 1 === preg_match('/^[A-Z]$/', $first) ? $first : '#';
            $groups[$letter][] = $entry;
        }

        // `#` last, the way a printed index puts figures after Z.
        uksort($groups, static fn (string $a, string $b): int => ('#' === $a) <=> ('#' === $b) ?: $a <=> $b);

        $index = [];
        foreach ($groups as $letter => $list) {
            $index[] = ['letter' => $letter, 'entries' => $list];
        }

        return [
            'columns' => (int) $zone['columns'],
            'variant' => (string) $zone['cardVariant'],
            'cards' => [],
            'index' => $index,
        ];
    }

    /**
     * The newest publications, and the latest releases of the repositories the
     * zone names when GitHub is switched on, in one list by date.
     *
     * Two sources and one order, because a reader of "what moved lately" does
     * not care which module a change came from - only that it is recent.
     *
     * @param array<string, mixed> $zone
     *
     * @return list<array{kind: string, title: string, url: string, date: string, dateLabel: string, detail: string}>
     */
    public function activityFeedView(array $zone, string $locale, ?int $currentPostId): array
    {
        $dates = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE);
        $entries = [];

        $posts = $this->postRepository->findLatestPublished($locale, (int) $zone['limit'], $zone['postTypeId'], null, $currentPostId);
        // Thumbnails and terms for the whole list at once, as the archive
        // pages do: read card by card, a feed of twelve cost twelve queries.
        $this->postRepository->warmCards($posts);

        foreach ($posts as $post) {
            $card = $this->postCard($post, $locale);
            $published = $post->getPublishedAt();
            if (null === $card) {
                continue;
            }

            if (null === $published) {
                continue;
            }

            $entries[] = [
                'kind' => 'post',
                'title' => (string) $card['title'],
                'url' => $this->urlGenerator->generate('editorial_post', ['locale' => $locale, 'postTypeSlug' => $card['postTypeSlug'], 'slug' => $card['slug']]),
                'date' => $published->format(DATE_ATOM),
                'dateLabel' => (string) $dates->format($published),
                'detail' => (string) ($card['description'] ?? ''),
            ];
        }

        if ($zone['options']['feedGithub'] ?? false) {
            foreach ($this->gitHubActivityView->build($locale, ['githubMode' => 'releases', 'githubRepos' => $zone['options']['githubRepos'] ?? []])['releases'] ?? [] as $release) {
                if ('' === $release['publishedAt']) {
                    continue;
                }

                $entries[] = [
                    'kind' => 'release',
                    'title' => $release['repo'].' '.$release['name'],
                    'url' => $release['url'],
                    'date' => new DateTimeImmutable($release['publishedAt'])->format(DATE_ATOM),
                    'dateLabel' => $release['dateLabel'],
                    'detail' => $release['summary'],
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return array_slice($entries, 0, max(1, (int) $zone['limit']));
    }

    /**
     * A linked publication is shown in the language of the page it appears on,
     * which is why the id is shared and this is not: the post carries its own
     * translations and picking the right one is the renderer's job.
     *
     * Built here rather than through PostSerializer, for two reasons. It would
     * be a circular dependency - the serialiser calls this builder to hand the
     * editor a resolved layout. And `serializeCard` computes terms and custom
     * fields, which cost queries and which a grid card does not show: six
     * fields is the whole of it.
     *
     * @return array<string, mixed>|null null when the post is gone, trashed,
     *                                   or has nothing written in this locale
     */
    public function postCard(?PostInterface $post, string $locale): ?array
    {
        if (!$post instanceof PostInterface || $post->isTrashed()) {
            return null;
        }

        $translation = $post->getTranslation($locale);
        $thumbnail = $this->thumbnailPresenter->present($post);

        // A card with no title and no address is a link to nowhere. That is
        // what an untranslated publication looks like, and it should leave a
        // gap rather than an empty box.
        if (null === $translation?->getTitle() || null === $translation->getSlug()) {
            return null;
        }

        return [
            'id' => $post->getId(),
            'title' => $translation->getTitle(),
            'slug' => $translation->getSlug(),
            // The description, never the meta description: that one is written
            // for a search snippet and cut around 160 characters.
            'description' => $translation->getDescription(),
            'postTypeSlug' => $post->getPostType()->getSlug(),
            // Named the same way serializeCard names them, so one card
            // partial can read either shape. Spreading the presenter's own
            // keys would have put a `url` on a card, which reads as the
            // publication's address rather than its picture's.
            'thumbnailUrl' => $thumbnail['url'],
            'thumbnailFitClass' => $thumbnail['objectFitClass'],
            'thumbnailFocalPosition' => $thumbnail['focalPosition'],
        ];
    }
}
