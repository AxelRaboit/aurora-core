<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Manager;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Post\Dto\PostGalleryInput;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Gallery\GalleryNormalizer;
use Doctrine\ORM\EntityManagerInterface;

use function count;

/**
 * Writes a publication's gallery, and touches nothing else on it.
 *
 * The counterpart of {@see PostGalleryInput}: that class limits what can be
 * asked for, this one limits what is written. Two columns, and they are named
 * here rather than reached through `applyInput` precisely so that adding a field
 * to the full editor cannot widen this path by accident.
 *
 * Not a subclass of `PostManager` and not a method on it. `PostManager::update`
 * runs the whole post - slugs, scheduling, publication state, revisions - and
 * inheriting it would mean the narrow endpoint's safety rested on which parent
 * methods happened to be called. A separate manager has nothing to opt out of.
 *
 * The normaliser is the same one the full editor uses, so a picture that would be
 * refused there is refused here: the surface is narrower, the rules are not looser.
 */
class PostGalleryManager implements PostGalleryManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly GalleryNormalizer $galleryNormalizer,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function update(PostInterface $post, PostGalleryInput $input): void
    {
        $layout = $this->galleryNormalizer->normalizeLayout($input->getLayout());
        $post->setGalleryLayout($layout);

        // Every locale the post already has, not only the ones the request
        // mentioned. A picture removed from the layout has to lose its caption in
        // every language, including the ones this screen was not showing - and
        // `normalizeContent` drops words whose item is gone, so passing an empty
        // set for an unsent locale is the correct instruction rather than a gap.
        foreach ($post->getTranslations() as $translation) {
            $locale = $translation->getLocale();

            $translation->setGallery(
                $this->galleryNormalizer->normalizeContent($input->wordsFor($locale), $layout),
            );
        }

        $this->auditLogger->log('editorial', 'post.gallery_updated', 'Post', $post->getId(), [
            'items' => count($layout['items'] ?? []),
        ]);

        $this->entityManager->flush();
    }
}
