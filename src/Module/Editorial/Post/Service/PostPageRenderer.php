<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Editorial\Comment\Manager\CommentManagerInterface;
use Aurora\Module\Editorial\Post\Banner\BannerViewBuilder;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\Seo\Service\AlternatesBuilder;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use DateTimeInterface;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Renders one post as a public page.
 *
 * Kept out of the controller because more than one caller needs it: a post
 * page is also what a failed comment submission has to re-render, with the
 * reader's text still in the form.
 */
final readonly class PostPageRenderer
{
    public function __construct(
        private Environment $twig,
        private ThemeResolver $themeResolver,
        private Context $context,
        private ThemeContext $themeContext,
        private BlocksRenderer $blocksRenderer,
        private AlternatesBuilder $alternatesBuilder,
        private DocumentUrlGenerator $documentUrlGenerator,
        private CommentManagerInterface $commentManager,
        private BannerViewBuilder $bannerViewBuilder,
        private GridViewBuilder $gridViewBuilder,
    ) {}

    public function render(PostInterface $post, string $locale): Response
    {
        $translation = $post->getTranslation($locale);
        if (!$translation instanceof PostTranslationInterface) {
            // The caller decides what a missing translation means — a 404, or
            // a redirect to a locale that has one. Guessing here would hide it.
            throw new LogicException(sprintf('Post #%d has no translation for locale "%s".', $post->getId(), $locale));
        }

        $body = $this->twig->render($this->themeResolver->resolve('editorial/post/index'), [
            'locale' => $locale,
            'context' => $this->context,
            'themeContext' => $this->themeContext,
            'postData' => [
                'id' => $post->getId(),
                'publishedAt' => $post->getPublishedAt()?->format(DateTimeInterface::ATOM),
                'postType' => ['slug' => $post->getPostType()->getSlug()],
                'postTypeSlug' => $post->getPostType()->getSlug(),
            ],
            'translationData' => $this->translationData($translation, $post->getFeaturedMedia()),
            'featuredMediaData' => $this->mediaData($post->getFeaturedMedia()),
            // null when the banner is off or empty, which is what the template
            // reads to fall back to the plain title header.
            'banner' => $this->bannerViewBuilder->build($post->getBannerLayout(), $translation->getBanner()),
            // Null when the post has no grid, which is what makes the template
            // fall back to the plain block column it has always rendered.
            'grid' => $this->gridViewBuilder->build($post->getGridLayout(), $translation->getGrid(), $locale),
            'content' => $this->blocksRenderer->render($translation->getBlocks(), $locale),
            'terms' => $this->postTerms($post, $locale),
            'alternates' => $this->alternatesBuilder->forPost($post),
            // The thread itself is fetched by the browser rather than
            // rendered here: comments are the one part of the page that
            // changes between two readers of the same cached HTML.
            'commentsEnabled' => $this->commentManager->areCommentsEnabled($post),
        ]);

        $response = new Response($body);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /** @return array<string, mixed> */
    private function translationData(PostTranslationInterface $translation, ?DocumentInterface $featuredMedia): array
    {
        // Falls back to the featured image: a post shared without an explicit
        // social image should still show the one it already has.
        $ogImage = $translation->getOgImage() ?? $featuredMedia;

        return [
            'title' => $translation->getTitle(),
            'slug' => $translation->getSlug(),
            'description' => $translation->getDescription(),
            'metaTitle' => $translation->getMetaTitle(),
            'metaDescription' => $translation->getMetaDescription(),
            'canonicalUrl' => $translation->getCanonicalUrl(),
            'noindex' => $translation->isNoindex(),
            'ogImage' => $ogImage instanceof DocumentInterface
                ? ['publicUrl' => $this->documentUrlGenerator->publicUrl($ogImage)]
                : null,
            'jsonLd' => $translation->getJsonLd(),
            // Same gap the listing cards had: a post type whose meaning lives
            // in its custom fields could not render them on its own page.
            'customFields' => $translation->getCustomFields(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function mediaData(?DocumentInterface $media): ?array
    {
        if (!$media instanceof DocumentInterface) {
            return null;
        }

        $focalPosition = $this->documentUrlGenerator->focalPositionCss($media);
        $large = $this->documentUrlGenerator->variantUrl($media, 'large');

        return [
            'publicUrl' => $this->documentUrlGenerator->publicUrl($media),
            'url' => $large ?? $this->documentUrlGenerator->publicUrl($media),
            'alt' => $media->getAlt(),
            'focalPosition' => $focalPosition,
        ];
    }

    /**
     * Term names grouped by taxonomy, so a theme can show them without
     * reaching back into the entity graph from Twig.
     *
     * @return array<string, list<string>>
     */
    private function postTerms(PostInterface $post, string $locale): array
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
}
