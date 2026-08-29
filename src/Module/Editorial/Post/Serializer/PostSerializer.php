<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Serializer;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Gallery\GalleryViewBuilder;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\Post\Service\ThumbnailPresenter;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PostSerializerInterface::class)]
class PostSerializer implements PostSerializerInterface
{
    public function __construct(
        protected readonly LocaleContextInterface $localeContext,
        protected readonly DocumentUrlGenerator $documentUrlGenerator,
        protected readonly BannerViewBuilder $bannerViewBuilder,
        protected readonly GridViewBuilder $gridViewBuilder,
        protected readonly GalleryViewBuilder $galleryViewBuilder,
        protected readonly ThumbnailPresenter $thumbnailPresenter,
    ) {}

    public function serializeReference(PostInterface $post): array
    {
        return [
            'id' => $post->getId(),
            'title' => $this->preferredTitle($post),
            'status' => $post->getStatus()->value,
            'postTypeId' => $post->getPostType()->getId(),
            'postType' => $post->getPostType()->getLabel(),
        ];
    }

    public function serialize(PostInterface $post): array
    {
        $translation = $post->getTranslation($this->localeContext->getDefaultLocale());

        return [
            'id' => $post->getId(),
            'reference' => $post->getReference(),
            'version' => $post->getVersion(),
            'status' => $post->getStatus()->value,
            'postType' => [
                'id' => $post->getPostType()->getId(),
                'label' => $post->getPostType()->getLabel(),
                'slug' => $post->getPostType()->getSlug(),
            ],
            'title' => $translation?->getTitle(),
            'slug' => $translation?->getSlug(),
            'termIds' => $this->ids($post->getTerms()),
            'relatedPostIds' => $this->ids($post->getRelatedPosts()),
            'publishedAt' => $post->getPublishedAt()?->format(DateTimeInterface::ATOM),
            'scheduledAt' => $post->getScheduledAt()?->format(DateTimeInterface::ATOM),
            'deletedAt' => $post->getDeletedAt()?->format(DateTimeInterface::ATOM),
            'trashed' => $post->isTrashed(),
            'commentsEnabled' => $post->isCommentsEnabled(),
            'titleVisible' => $post->isTitleVisible(),
            'createdAt' => $post->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updatedAt' => $post->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    public function serializeFull(PostInterface $post): array
    {
        $translations = [];
        foreach ($post->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = $this->serializeTranslation($translation);
        }

        return [
            ...$this->serialize($post),
            'thumbnailId' => $post->getThumbnail()?->getId(),
            'thumbnailUrl' => $this->documentUrlGenerator->publicUrl($post->getThumbnail()),
            'thumbnailFit' => $post->getThumbnailFit()->value,
            // Null when the publication does not override the document's, which
            // is what the editor shows as "inherited".
            'thumbnailFocalX' => $post->getThumbnailFocalX(),
            'thumbnailFocalY' => $post->getThumbnailFocalY(),
            // The *document's* own point, not the effective one: this is what
            // the picker shows as "inherited", so it has to keep saying what
            // clearing the override would fall back to.
            'thumbnailFocalPosition' => $this->documentUrlGenerator->focalPositionCss($post->getThumbnail()),
            // Resolved on the way out, so a post saved before the banner
            // existed reaches the editor as a complete shape instead of an
            // empty array it would have to guard against - and so a picker can
            // preview the image it already holds rather than just its id.
            // Texts are left out: they belong to whichever locale is open.
            'bannerLayout' => $this->bannerViewBuilder->buildForEditor($post->getBannerLayout(), []),
            // Same treatment for the grid: resolved so a picker can preview the
            // image it already holds rather than just its id, and content left
            // out because it belongs to whichever locale is open.
            'gridLayout' => $this->gridViewBuilder->buildForEditor(
                $post->getGridLayout(),
                [],
                $this->localeContext->getDefaultLocale(),
            ),
            // And the gallery, for the same reason as the two above: resolved so
            // the picker previews the pictures it already holds, and its words
            // left out because they belong to whichever locale is open.
            'galleryLayout' => $this->galleryViewBuilder->buildForEditor($post->getGalleryLayout(), []),
            // What the last review decided, so the editor can show the author what
            // to change rather than leaving them to guess why it came back.
            'reviewNote' => $post->getReviewNote(),
            'reviewedAt' => $post->getReviewedAt()?->format(DateTimeInterface::ATOM),
            'reviewedByName' => $post->getReviewedBy()?->getName(),
            'translations' => $translations,
            'relatedPosts' => array_map(
                $this->serializeReference(...),
                $post->getRelatedPosts()->toArray(),
            ),
        ];
    }

    public function serializeCard(PostInterface $post, string $locale): array
    {
        $translation = $post->getTranslation($locale);
        $thumbnail = $this->thumbnailPresenter->present($post);

        return [
            'id' => $post->getId(),
            'title' => $translation?->getTitle(),
            'slug' => $translation?->getSlug(),
            // The description, never the meta description: that one is written
            // for a search snippet and cut around 160 characters. Rendering it
            // here made one string serve two different readers.
            'description' => $translation?->getDescription(),
            'publishedAt' => $post->getPublishedAt()?->format(DateTimeInterface::ATOM),
            'postTypeSlug' => $post->getPostType()->getSlug(),
            'thumbnailUrl' => $thumbnail['url'],
            'thumbnailFitClass' => $thumbnail['objectFitClass'],
            'thumbnailFocalPosition' => $thumbnail['focalPosition'],
            // A card used to carry only what a blog post needs - title, teaser,
            // image. A post type whose meaning lives in its custom fields (a
            // room's price, a product's weight) could not be listed usefully at
            // all: the archive had nothing to show but a headline.
            'customFields' => $translation?->getCustomFields() ?? [],
            'terms' => $this->cardTerms($post, $locale),
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeTranslation(PostTranslationInterface $translation): array
    {
        return [
            'title' => $translation->getTitle(),
            'slug' => $translation->getSlug(),
            // Only the words. The design is serialised once on the post, so
            // switching locale in the editor swaps the copy and leaves the
            // layout standing - which is the whole point of the split.
            'banner' => $translation->getBanner(),
            'grid' => $translation->getGrid(),
            'gallery' => $translation->getGallery(),
            'description' => $translation->getDescription(),
            'metaTitle' => $translation->getMetaTitle(),
            'metaDescription' => $translation->getMetaDescription(),
            'customFields' => $translation->getCustomFields(),
            'ogImageMediaId' => $translation->getOgImage()?->getId(),
            'ogImageUrl' => $this->documentUrlGenerator->publicUrl($translation->getOgImage()),
            'ogImageFocalPosition' => $this->documentUrlGenerator->focalPositionCss($translation->getOgImage()),
            'canonicalUrl' => $translation->getCanonicalUrl(),
            'noindex' => $translation->isNoindex(),
            'focusKeyword' => $translation->getFocusKeyword(),
            'jsonLd' => $translation->getJsonLd(),
        ];
    }

    /**
     * Term names for a card, grouped by taxonomy - enough to draw a row of
     * badges without a query per post.
     *
     * @return array<string, list<string>>
     */
    protected function cardTerms(PostInterface $post, string $locale): array
    {
        $terms = [];
        foreach ($post->getTerms() as $term) {
            $name = $term->getTranslation($locale)?->getName();
            if (null !== $name) {
                $terms[$term->getTaxonomy()->getSlug()][] = $name;
            }
        }

        return $terms;
    }

    /**
     * Falls back to any translation: a post written in one locale still
     * needs a name in a picker shown in another.
     */
    private function preferredTitle(PostInterface $post): ?string
    {
        $translation = $post->getTranslation($this->localeContext->getDefaultLocale());

        return $translation?->getTitle() ?? ($post->getTranslations()->first() ?: null)?->getTitle();
    }

    /**
     * @param iterable<PostInterface|TaxonomyTermInterface> $collection
     *
     * @return list<int>
     */
    private function ids(iterable $collection): array
    {
        $ids = [];
        foreach ($collection as $item) {
            $id = $item->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
