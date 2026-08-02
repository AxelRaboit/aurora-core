<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Service;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Seo\Dto\SitemapData;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Walks the published site once and writes the sitemap.
 *
 * Everything a reader can reach on the public front is listed, and nothing
 * else: the home page and archives per locale, every published post in every
 * locale it exists in, and the taxonomy terms that actually have something
 * behind them.
 */
final readonly class SitemapBuilder
{
    public function __construct(
        private PostRepository $postRepository,
        private PostTypeRepository $postTypeRepository,
        private TaxonomyRepository $taxonomyRepository,
        private Context $context,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function buildData(): SitemapData
    {
        /** @var array<string, int> $byLocale */
        $byLocale = [];
        /** @var array<string, int> $byPostType */
        $byPostType = [];
        $noindex = 0;

        $home = $this->homeEntries($byLocale);
        $archives = $this->archiveEntries($byLocale);
        $posts = $this->postEntries($byLocale, $byPostType, $noindex);
        $terms = $this->termEntries($byLocale);

        arsort($byPostType);
        ksort($byLocale);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', [...$home, ...$archives, ...$posts, ...$terms])
            .'</urlset>';

        return new SitemapData(
            xml: $xml,
            counts: [
                'home' => count($home),
                'archives' => count($archives),
                'posts' => count($posts),
                'terms' => count($terms),
            ],
            byPostType: $byPostType,
            byLocale: $byLocale,
            noindex: $noindex,
            generatedAt: new DateTimeImmutable(),
        );
    }

    /**
     * @param array<string, int> $byLocale
     *
     * @return list<string>
     */
    private function homeEntries(array &$byLocale): array
    {
        $entries = [];
        foreach ($this->context->activeLocaleCodes() as $code) {
            $entries[] = $this->urlEntry(
                $this->urlGenerator->generate('editorial_home', ['locale' => $code], UrlGeneratorInterface::ABSOLUTE_URL),
            );
            $byLocale[$code] = ($byLocale[$code] ?? 0) + 1;
        }

        return $entries;
    }

    /**
     * @param array<string, int> $byLocale
     *
     * @return list<string>
     */
    private function archiveEntries(array &$byLocale): array
    {
        $archived = array_filter(
            $this->postTypeRepository->findAllWithRelations(),
            static fn ($postType): bool => $postType->hasArchive(),
        );

        $entries = [];
        foreach ($this->context->activeLocaleCodes() as $code) {
            foreach ($archived as $postType) {
                $entries[] = $this->urlEntry(
                    $this->urlGenerator->generate('editorial_archive', [
                        'locale' => $code,
                        'postTypeSlug' => $postType->getSlug(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                );
                $byLocale[$code] = ($byLocale[$code] ?? 0) + 1;
            }
        }

        return $entries;
    }

    /**
     * Every published translation, judged on its own.
     *
     * `noindex` is set per translation because that is where the checkbox
     * lives: a post that asks not to be indexed in French still wants its
     * English page found. Reading the default locale's flag and skipping the
     * whole post would drop URLs the editor never asked to hide — and would
     * not even show up in the counter, since the skip happened before it.
     *
     * @param array<string, int> $byLocale
     * @param array<string, int> $byPostType
     *
     * @return list<string>
     */
    private function postEntries(array &$byLocale, array &$byPostType, int &$noindex): array
    {
        $entries = [];

        foreach ($this->postRepository->findAllPublishedForSitemap() as $post) {
            $postTypeSlug = $post->getPostType()->getSlug();

            foreach ($post->getTranslations() as $translation) {
                $code = $translation->getLocale();
                $slug = $translation->getSlug();
                if (null === $slug) {
                    continue;
                }

                if ('' === $slug) {
                    continue;
                }

                if (!$this->context->isLocaleActive($code)) {
                    continue;
                }

                if ($translation->isNoindex()) {
                    ++$noindex;

                    continue;
                }

                $entries[] = $this->urlEntry(
                    $this->urlGenerator->generate('editorial_post', [
                        'locale' => $code,
                        'postTypeSlug' => $postTypeSlug,
                        'slug' => $slug,
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                    $this->lastModified($post),
                );

                $byLocale[$code] = ($byLocale[$code] ?? 0) + 1;
                $byPostType[$postTypeSlug] = ($byPostType[$postTypeSlug] ?? 0) + 1;
            }
        }

        return $entries;
    }

    /**
     * Only terms with a published post behind them.
     *
     * An empty term page is a page with a heading and nothing under it —
     * listing every one of them fills the sitemap with thin content, which is
     * worse for the site than the pages being missing.
     *
     * @param array<string, int> $byLocale
     *
     * @return list<string>
     */
    private function termEntries(array &$byLocale): array
    {
        $entries = [];

        foreach ($this->taxonomyRepository->findAllForIndex() as $taxonomy) {
            foreach ($taxonomy->getTerms() as $term) {
                if (!$this->hasPublishedPost($term->getPosts())) {
                    continue;
                }

                foreach ($this->context->activeLocaleCodes() as $code) {
                    $slug = $term->getTranslation($code)?->getSlug();
                    if (null === $slug) {
                        continue;
                    }

                    if ('' === $slug) {
                        continue;
                    }

                    $entries[] = $this->urlEntry(
                        $this->urlGenerator->generate('editorial_term', [
                            'locale' => $code,
                            'taxonomySlug' => $taxonomy->getSlug(),
                            'termSlug' => $slug,
                        ], UrlGeneratorInterface::ABSOLUTE_URL),
                    );
                    $byLocale[$code] = ($byLocale[$code] ?? 0) + 1;
                }
            }
        }

        return $entries;
    }

    /** @param iterable<PostInterface> $posts */
    private function hasPublishedPost(iterable $posts): bool
    {
        foreach ($posts as $post) {
            if ($post->isPublished() && !$post->isTrashed()) {
                return true;
            }
        }

        return false;
    }

    private function lastModified(PostInterface $post): string
    {
        return $post->getUpdatedAt()->format(DateTimeInterface::ATOM);
    }

    private function urlEntry(string $url, ?string $lastmod = null): string
    {
        $entry = sprintf('<url><loc>%s</loc>', $this->escape($url));
        if (null !== $lastmod) {
            $entry .= sprintf('<lastmod>%s</lastmod>', $lastmod);
        }

        return $entry.'</url>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
