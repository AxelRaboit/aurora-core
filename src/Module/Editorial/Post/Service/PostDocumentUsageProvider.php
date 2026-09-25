<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Ged\Document\Contract\BatchDocumentUsageProviderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Says which posts draw a given picture, before somebody deletes it.
 *
 * A post points at a document three times over - its cover, each
 * translation's social image, and the pictures of its gallery - and none of
 * the three was reported. Deleting the file blanked a cover or emptied a
 * gallery slot with the deletion screen having said nobody used it.
 *
 * One line per post, not per pointer: a picture used as both the cover and
 * the first gallery item is one thing to know about, and the person deciding
 * whether to delete opens the post either way.
 */
final readonly class PostDocumentUsageProvider implements BatchDocumentUsageProviderInterface
{
    public function __construct(
        private PostRepository $posts,
        private LocaleContextInterface $localeContext,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    /** @return list<array{type: string, label: string, detail?: ?string, href?: ?string}> */
    public function findUsages(int $documentId): array
    {
        $locale = $this->localeContext->getDefaultLocale();
        $usages = [];

        foreach ($this->posts->findUsingDocument($documentId) as $post) {
            $usages[] = [
                'type' => 'editorial.post',
                // A draft in a language that has no title yet still has to be
                // nameable, or the list shows a blank row and says nothing.
                'label' => $post->getTranslation($locale)?->getTitle()
                    ?? $this->translator->trans('backend.posts.usage_untitled'),
                'detail' => $this->translator->trans('backend.posts.usage_detail'),
                'href' => $this->urlGenerator->generate(
                    'backend_editorial_posts_edit',
                    ['id' => $post->getId()],
                ),
            ];
        }

        return $usages;
    }

    /**
     * The same answer for a page of documents, in one narrowing and one walk.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countUsagesFor(array $documentIds): array
    {
        return $this->posts->countUsagesByDocument($documentIds);
    }
}
