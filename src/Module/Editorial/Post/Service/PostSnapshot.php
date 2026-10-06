<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Entity\PostInterface;

use function array_values;

use const DATE_ATOM;

/**
 * What a revision keeps of a post.
 *
 * The shape lived in a private method of the manager, which is the only
 * place a revision is born - as long as nobody tries to make one elsewhere.
 * The demo data set needed it: it writes its posts directly, so no revision
 * existed, and the "Historique des versions" modal opened on an empty
 * screen. That is precisely the feature the module's public page promises.
 *
 * Moved out here rather than copied into the fixtures: two definitions of
 * what a revision holds would drift, and the second would only show at
 * restore time - that is, the day someone counts on it.
 */
final readonly class PostSnapshot
{
    /** @return array<string, mixed> */
    public function build(PostInterface $post): array
    {
        $translations = [];
        foreach ($post->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = [
                'title' => $translation->getTitle(),
                'slug' => $translation->getSlug(),
                // The body, which is a grid and lives in two halves: what each
                // zone carries is here, the arrangement is on the post further
                // down. Taking one without the other restores words without a
                // place, or a place without words.
                'grid' => $translation->getGrid(),
                'description' => $translation->getDescription(),
                'metaTitle' => $translation->getMetaTitle(),
                'metaDescription' => $translation->getMetaDescription(),
                'customFields' => $translation->getCustomFields(),
                'ogImageMediaId' => $translation->getOgImage()?->getId(),
                'canonicalUrl' => $translation->getCanonicalUrl(),
                'noindex' => $translation->isNoindex(),
                'focusKeyword' => $translation->getFocusKeyword(),
                'jsonLd' => $translation->getJsonLd(),
            ];
        }

        return [
            'status' => $post->getStatus()->value,
            'postTypeId' => $post->getPostType()->getId(),
            'thumbnailId' => $post->getThumbnail()?->getId(),
            'termIds' => array_values($post->getTerms()->map(static fn ($term): ?int => $term->getId())->toArray()),
            'relatedPostIds' => array_values($post->getRelatedPosts()->map(static fn ($related): ?int => $related->getId())->toArray()),
            'publishedAt' => $post->getPublishedAt()?->format(DATE_ATOM),
            'scheduledAt' => $post->getScheduledAt()?->format(DATE_ATOM),
            'gridLayout' => $post->getGridLayout(),
            'bannerLayout' => $post->getBannerLayout(),
            'translations' => $translations,
        ];
    }
}
