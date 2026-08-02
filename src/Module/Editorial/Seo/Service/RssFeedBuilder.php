<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Service;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use DateTimeInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The RSS feed of the dated stream, one per locale.
 *
 * Only `article` is fed: a feed is a chronology, and pages have no place in
 * one. A site whose `article` type has been deleted gets an empty but valid
 * channel rather than an error — a reader's feed client should say "nothing
 * new", not fail to parse.
 */
final readonly class RssFeedBuilder
{
    /** Newest posts carried in the feed. */
    private const int ITEM_LIMIT = 20;

    public function __construct(
        private PostRepository $postRepository,
        private PostTypeRepository $postTypeRepository,
        private Context $context,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function buildXml(string $locale): string
    {
        $items = implode('', $this->items($locale));

        $siteName = $this->escape($this->context->siteName());
        $siteDescription = $this->escape($this->context->siteDescription() ?? '');
        $homeUrl = $this->escape(
            $this->urlGenerator->generate('editorial_home', ['locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
        );
        $selfUrl = $this->escape(
            $this->urlGenerator->generate('editorial_rss', ['locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
        );

        // The atom namespace is declared on <rss>, where a reader expects to
        // find it, rather than on the one element that happens to use it.
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'
            .'<channel>'
            .sprintf('<title>%s</title>', $siteName)
            .sprintf('<link>%s</link>', $homeUrl)
            .sprintf('<description>%s</description>', $siteDescription)
            .sprintf('<language>%s</language>', $this->escape($locale))
            .sprintf('<atom:link href="%s" rel="self" type="application/rss+xml"/>', $selfUrl)
            .$items
            .'</channel>'
            .'</rss>';
    }

    /** @return list<string> */
    private function items(string $locale): array
    {
        $postType = $this->postTypeRepository->findOneBySlug('article');
        if (!$postType instanceof PostTypeInterface) {
            return [];
        }

        $result = $this->postRepository->findPublishedByPostType(
            (int) $postType->getId(),
            1,
            self::ITEM_LIMIT,
            $locale,
        );

        $items = [];
        foreach ($result['items'] as $post) {
            $translation = $post->getTranslation($locale);
            $slug = $translation?->getSlug();
            if (null === $translation) {
                continue;
            }

            if (null === $slug) {
                continue;
            }

            if ('' === $slug) {
                continue;
            }

            $link = $this->escape($this->urlGenerator->generate('editorial_post', [
                'locale' => $locale,
                'postTypeSlug' => $post->getPostType()->getSlug(),
                'slug' => $slug,
            ], UrlGeneratorInterface::ABSOLUTE_URL));

            // The meta description is written for search engines and is often
            // blank; the summary is what an editor writes for a human, which
            // is who reads a feed.
            $description = $translation->getDescription() ?? $translation->getMetaDescription() ?? '';

            $items[] = '<item>'
                .sprintf('<title>%s</title>', $this->escape((string) $translation->getTitle()))
                .sprintf('<link>%s</link>', $link)
                .sprintf('<guid isPermaLink="true">%s</guid>', $link)
                .sprintf('<description>%s</description>', $this->escape($description))
                .sprintf('<pubDate>%s</pubDate>', ($post->getPublishedAt() ?? $post->getCreatedAt())->format(DateTimeInterface::RSS))
                .'</item>';
        }

        return $items;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
