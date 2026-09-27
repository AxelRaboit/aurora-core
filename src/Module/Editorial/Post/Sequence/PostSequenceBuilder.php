<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Sequence;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyTermRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_key_last;
use function usort;

/**
 * The table of contents beside a page, and the way to the next one.
 *
 * What separates a documentation from a list of articles: a reader who lands
 * on one page has to see where it sits and what comes after it. A blog has no
 * use for either, so this only runs for a type of content that declares it is
 * read in sequence.
 *
 * The tree comes from the hierarchical taxonomy attached to the type - rubrics
 * and sub-rubrics, already ordered, already translated - and the pages hang
 * off it by their term. Nothing here is a second structure to keep in step
 * with the first: the summary is the taxonomy, drawn.
 *
 * Everything is read in three queries and grouped in memory rather than asked
 * per rubric: the pages, their rubrics, and the tree with its names. What
 * matters is that the count of queries grows with neither the pages nor the
 * rubrics - it did with both, one query per page for its rubrics and one per
 * rubric for its name, some sixty on a documentation page.
 */
final readonly class PostSequenceBuilder
{
    public function __construct(
        private PostRepository $postRepository,
        private UrlGeneratorInterface $urlGenerator,
        private TaxonomyTermRepository $termRepository,
    ) {}

    /**
     * @return array{summary: list<array<string, mixed>>, searchUrl: string, previous: ?array<string, string>, next: ?array<string, string>}|null
     */
    public function build(PostInterface $post, string $locale): ?array
    {
        $postType = $post->getPostType();

        if (!$postType->supports('sequence')) {
            return null;
        }

        $taxonomy = $this->tree($postType->getTaxonomies());

        if (!$taxonomy instanceof TaxonomyInterface) {
            return null;
        }

        // Every published page of the type, once, in reading order. The
        // repository already sorts by position then date, which is the order
        // the summary is meant to show.
        $pages = $this->postRepository->findLatestPublished($locale, 500, $postType->getId());
        $this->postRepository->warmCards($pages);
        $terms = $this->termRepository->findByTaxonomyOrderedForDisplay($taxonomy);

        /** @var array<int, list<PostInterface>> $byTerm */
        $byTerm = [];

        foreach ($pages as $page) {
            foreach ($page->getTerms() as $term) {
                $byTerm[(int) $term->getId()][] = $page;
            }
        }

        $summary = [];
        $flat = [];

        foreach ($this->children($terms, null) as $section) {
            $entries = [];

            foreach ($this->children($terms, $section) as $rubric) {
                $entries[] = $this->rubricView($rubric, $byTerm, $post, $locale, $flat);
            }

            // A section with no sub-rubric holds its pages directly, which is
            // how the shallow parts of a tree stay usable.
            if ([] === $entries) {
                $entries[] = $this->rubricView($section, $byTerm, $post, $locale, $flat);
            }

            // Une section dont aucune rubrique ne porte de page n'a rien à
            // dire : elle s'affichait quand même, en titre suivi de blanc.
            // Cela arrive dès qu'un terme existe sans publication visible,
            // une rubrique dépubliée ou pas encore écrite.
            $hasPages = array_any($entries, static fn (array $entry): bool => [] !== $entry['pages']);

            if (!$hasPages) {
                continue;
            }

            $summary[] = [
                'label' => $section->getTranslation($locale)?->getName() ?? '',
                'rubrics' => $entries,
            ];
        }

        return [
            'summary' => $summary,
            // The search of this type, not of the site: a reader looking
            // something up in a documentation is not looking for a blog
            // post that happens to share a word.
            'searchUrl' => $this->urlGenerator->generate('editorial_home_search', [
                'locale' => $locale,
                'type' => $postType->getSlug(),
            ]),
            ...$this->neighbours($flat, $post),
        ];
    }

    /**
     * The taxonomy the summary is drawn from.
     *
     * The hierarchical one, because a flat list of tags is not a tree and
     * would draw a summary with one level and no order anybody chose. The
     * first one wins when a type carries several: a second hierarchical
     * taxonomy on the same type is a modelling accident, not a case to guess
     * at.
     *
     * @param iterable<TaxonomyInterface> $taxonomies
     */
    private function tree(iterable $taxonomies): ?TaxonomyInterface
    {
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy->isHierarchical()) {
                return $taxonomy;
            }
        }

        return null;
    }

    /**
     * @param iterable<TaxonomyTermInterface> $terms
     *
     * @return list<TaxonomyTermInterface>
     */
    private function children(iterable $terms, ?TaxonomyTermInterface $parent): array
    {
        $children = [];

        foreach ($terms as $term) {
            if ($term->getParent() === $parent) {
                $children[] = $term;
            }
        }

        usort($children, static fn (TaxonomyTermInterface $a, TaxonomyTermInterface $b): int => $a->getPosition() <=> $b->getPosition());

        return $children;
    }

    /**
     * @param array<int, list<PostInterface>> $byTerm
     * @param list<array<string, string|int>> $flat   filled in reading order, for the neighbours
     *
     * @return array<string, mixed>
     */
    private function rubricView(
        TaxonomyTermInterface $rubric,
        array $byTerm,
        PostInterface $current,
        string $locale,
        array &$flat,
    ): array {
        $pages = [];

        foreach ($byTerm[(int) $rubric->getId()] ?? [] as $page) {
            $translation = $page->getTranslation($locale);

            if (!$translation instanceof PostTranslationInterface) {
                continue;
            }

            $entry = [
                'id' => (int) $page->getId(),
                'title' => (string) $translation->getTitle(),
                'url' => $this->url($page, $translation, $locale),
            ];

            $flat[] = $entry;

            $pages[] = ['title' => $entry['title'], 'url' => $entry['url'], 'current' => $entry['id'] === $current->getId()];
        }

        return [
            'label' => $rubric->getTranslation($locale)?->getName() ?? '',
            'pages' => $pages,
        ];
    }

    /**
     * The page before and the page after, across the whole sequence.
     *
     * Across, not inside the rubric: a reader at the end of "Éditorial" wants
     * the first page of "Médiathèque", not a dead end. The rubric is what the
     * summary shows; the sequence is what "next" follows.
     *
     * @param list<array<string, string|int>> $flat
     *
     * @return array{previous: ?array<string, string>, next: ?array<string, string>}
     */
    private function neighbours(array $flat, PostInterface $post): array
    {
        $position = array_find_key($flat, fn ($entry): bool => $entry['id'] === $post->getId());
        // A page outside the tree - carrying no rubric, or a rubric of another
        // taxonomy - has no neighbours rather than the first and second of the
        // sequence, which would be a lie about where the reader is.
        if (null === $position) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $this->entryAt($flat, $position - 1),
            'next' => $position === array_key_last($flat) ? null : $this->entryAt($flat, $position + 1),
        ];
    }

    /**
     * @param list<array<string, string|int>> $flat
     *
     * @return array<string, string>|null
     */
    private function entryAt(array $flat, int $index): ?array
    {
        if (!isset($flat[$index])) {
            return null;
        }

        return ['title' => (string) $flat[$index]['title'], 'url' => (string) $flat[$index]['url']];
    }

    private function url(PostInterface $post, PostTranslationInterface $translation, string $locale): string
    {
        return $this->urlGenerator->generate('editorial_post', [
            'locale' => $locale,
            'postTypeSlug' => $post->getPostType()->getSlug(),
            'slug' => $translation->getSlug(),
        ]);
    }
}
