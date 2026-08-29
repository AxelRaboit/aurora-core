<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Duplicate;

use Aurora\Module\Editorial\Post\Dto\PostInputFactoryInterface;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Copies a publication into a new draft.
 *
 * **Rebuilt as an input and run through the manager**, rather than copied field by
 * field. Everything the editor normalises - the three layouts, the words joined to
 * them by item id, the slug, the search index - happens once, in the one place that
 * already knows how. A hand-written copy would be a second implementation of
 * `applyInput` that drifts the first time somebody adds a field, and it would drift
 * silently: the duplicate would simply be missing something.
 *
 * What is deliberately *not* carried over is the point of the class:
 *
 * - **The status becomes a draft.** A copy that published itself would be a page
 *   live on the site that nobody wrote.
 * - **No publication or schedule date**, for the same reason.
 * - **No review note or decision.** They describe the original's history, and the
 *   copy has none.
 * - **The author is whoever pressed the button**, not whoever wrote the original -
 *   which `create()` already does from the session.
 * - **Revisions and preview links stay behind.** They point at a past this post
 *   does not have.
 *
 * The reference is left to the entity to mint. Two publications carrying one
 * reference is the sort of thing nobody notices until an invoice quotes it.
 */
final readonly class PostDuplicator
{
    public function __construct(
        private PostManagerInterface $postManager,
        private PostInputFactoryInterface $inputFactory,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {}

    public function duplicate(PostInterface $source): PostInterface
    {
        // The author is not passed: `create()` already takes it from the session,
        // which is the answer this wants anyway - the copy belongs to whoever
        // pressed the button, not to whoever wrote the original.
        $copy = $this->postManager->create($this->inputFactory->fromArray($this->payload($source)));

        $this->entityManager->flush();

        return $copy;
    }

    /**
     * The source, as the create endpoint would have received it.
     *
     * @return array<string, mixed>
     */
    private function payload(PostInterface $source): array
    {
        return [
            'postTypeId' => $source->getPostType()->getId(),
            'status' => 'draft',
            'thumbnailId' => $source->getThumbnail()?->getId(),
            'thumbnailFit' => $source->getThumbnailFit()->value,
            'thumbnailFocalX' => $source->getThumbnailFocalX(),
            'thumbnailFocalY' => $source->getThumbnailFocalY(),
            'commentsEnabled' => $source->isCommentsEnabled(),
            'titleVisible' => $source->isTitleVisible(),
            'bannerLayout' => $source->getBannerLayout(),
            'gridLayout' => $source->getGridLayout(),
            'galleryLayout' => $source->getGalleryLayout(),
            'termIds' => $this->ids($source->getTerms()),
            // The originals' relations, not a relation to the original. A copy is a
            // starting point, and pointing it at the post it came from would put a
            // "see also" on the page that nobody asked for.
            'relatedPostIds' => $this->ids($source->getRelatedPosts()),
            'translations' => $this->translations($source),
        ];
    }

    /**
     * Every language, retitled so the two are told apart.
     *
     * The suffix is not decoration: a list showing two rows with the same title is
     * a list where somebody edits the wrong one. The slug follows from the title,
     * so it comes out distinct without being handled here - which is also why no
     * slug is sent: passing the original's would collide, and passing a made-up one
     * would bypass the slugger.
     *
     * @return array<string, array<string, mixed>>
     */
    private function translations(PostInterface $source): array
    {
        $translations = [];

        foreach ($source->getTranslations() as $locale => $translation) {
            $translations[(string) $locale] = [
                'title' => $this->copyTitle($translation),
                'description' => $translation->getDescription(),
                'metaTitle' => $translation->getMetaTitle(),
                'metaDescription' => $translation->getMetaDescription(),
                'customFields' => $translation->getCustomFields(),
                'banner' => $translation->getBanner(),
                'grid' => $translation->getGrid(),
                'gallery' => $translation->getGallery(),
                'ogImageMediaId' => $translation->getOgImage()?->getId(),
                // No canonical URL. It points at the original's address, and a copy
                // announcing itself as that page is the one SEO mistake here that
                // would actually cost something.
                'noindex' => $translation->isNoindex(),
                'focusKeyword' => $translation->getFocusKeyword(),
                'jsonLd' => $translation->getJsonLd(),
            ];
        }

        return $translations;
    }

    private function copyTitle(PostTranslationInterface $translation): string
    {
        $title = $translation->getTitle();

        return sprintf(
            '%s %s',
            null !== $title && '' !== $title ? $title : $this->translator->trans('backend.posts.untitled'),
            $this->translator->trans('backend.posts.duplicate.suffix'),
        );
    }

    /**
     * @param iterable<object> $items
     *
     * @return list<int>
     */
    private function ids(iterable $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (method_exists($item, 'getId') && null !== $item->getId()) {
                $ids[] = (int) $item->getId();
            }
        }

        return $ids;
    }
}
