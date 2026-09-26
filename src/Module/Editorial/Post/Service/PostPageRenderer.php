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
use Aurora\Module\Editorial\Post\Gallery\GalleryViewBuilder;
use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Module\Editorial\Post\Sequence\PostSequenceBuilder;
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
        private AlternatesBuilder $alternatesBuilder,
        private DocumentUrlGenerator $documentUrlGenerator,
        private CommentManagerInterface $commentManager,
        private BannerViewBuilder $bannerViewBuilder,
        private GridViewBuilder $gridViewBuilder,
        private GalleryViewBuilder $galleryViewBuilder,
        private PostSequenceBuilder $sequenceBuilder,
        private ReadingTimeCalculator $readingTimeCalculator,
    ) {}

    public function render(PostInterface $post, string $locale): Response
    {
        $translation = $post->getTranslation($locale);
        if (!$translation instanceof PostTranslationInterface) {
            // The caller decides what a missing translation means - a 404, or
            // a redirect to a locale that has one. Guessing here would hide it.
            throw new LogicException(sprintf('Post #%d has no translation for locale "%s".', $post->getId(), $locale));
        }

        // Read once: the reading time is counted off the same resolved zones
        // the page is about to render, so the two can never disagree.
        $grid = $this->gridViewBuilder->build($post->getGridLayout(), $translation->getGrid(), $locale, $post->getId());

        $body = $this->twig->render($this->themeResolver->resolve('editorial/post/index'), [
            'locale' => $locale,
            'context' => $this->context,
            'themeContext' => $this->themeContext,
            'postData' => [
                'id' => $post->getId(),
                'publishedAt' => $post->getPublishedAt()?->format(DateTimeInterface::ATOM),
                'postType' => ['slug' => $post->getPostType()->getSlug()],
                'postTypeSlug' => $post->getPostType()->getSlug(),
                'accentColor' => $post->getAccentColor(),
                'highlight' => $post->getHighlight(),
                'highlightColor' => $post->getHighlightColor(),
            ],
            'translationData' => $this->translationData($translation, $post->getThumbnail()),
            // null when the banner is off or empty, which is what the template
            // reads to fall back to the plain title header.
            'banner' => $this->bannerViewBuilder->build($post->getBannerLayout(), $translation->getBanner()),
            // Null when the post has no grid, which is what makes the template
            // fall back to the plain block column it has always rendered.
            'grid' => $grid,
            // Zero when there is nothing to time - the grid is off, empty, or
            // resolved to nothing - which the template reads as "say
            // nothing" rather than printing "0 min de lecture".
            'readingTimeMinutes' => null !== $grid ? $this->readingTimeCalculator->minutesFor($translation->getGrid()) : 0,
            // Null when the gallery is off or has nothing to show, so the
            // template leaves the section out rather than printing an empty one.
            'gallery' => $this->galleryViewBuilder->build($post->getGalleryLayout(), $translation->getGallery()),
            'terms' => $this->postTerms($post, $locale),
            // Null for every type that is not read in sequence, which is all
            // of them but a documentation: the summary and the two neighbours
            // are what a page read in order needs and an article does not.
            'sequence' => $this->sequenceBuilder->build($post, $locale),
            'alternates' => $this->alternatesBuilder->forPost($post),
            // The thread itself is fetched by the browser rather than
            // rendered here: comments are the one part of the page that
            // changes between two readers of the same cached HTML.
            'commentsEnabled' => $this->commentManager->areCommentsEnabled($post),
            // Whether the page prints its own title and summary. Shared, not
            // per translation: it is a decision about the design.
            'titleVisible' => $post->isTitleVisible(),
            // Ce que cette publication repeint pour elle seule. Les clés sont
            // celles du thème - ThemeContext::SURFACES - parce que c'est lui
            // qui les résout, surface par surface, contre sa propre
            // configuration. Une valeur nulle n'est pas un choix : elle laisse
            // passer la couleur du thème.
            'surfaceOverrides' => [
                'background_color' => $post->getBackgroundColor(),
                'header_color' => $post->getHeaderColor(),
                'footer_color' => $post->getFooterColor(),
            ],
        ]);

        $response = new Response($body);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    /** @return array<string, mixed> */
    private function translationData(PostTranslationInterface $translation, ?DocumentInterface $thumbnail): array
    {
        // Falls back to the thumbnail: a post shared without an explicit social
        // image should still show the picture that stands for it everywhere
        // else. That fallback is the one job the thumbnail kept when it stopped
        // being rendered at the top of the page.
        $ogImage = $translation->getOgImage() ?? $thumbnail;

        return [
            'title' => $translation->getTitle(),
            'slug' => $translation->getSlug(),
            'description' => $translation->getDescription(),
            'metaTitle' => $translation->getMetaTitle(),
            'metaDescription' => $translation->getMetaDescription(),
            'canonicalUrl' => $translation->getCanonicalUrl(),
            'noindex' => $translation->isNoindex(),
            'ogImage' => $ogImage instanceof DocumentInterface
                ? [
                    // The `large` variant rather than the original: a share
                    // image is downloaded by every crawler that meets the
                    // link, and the original is whatever was uploaded - a
                    // 2.8 MB PNG in one case here, for a card that renders at
                    // 1200 pixels wide. The variant is capped at 1920, which
                    // is above what any platform asks for.
                    //
                    // Falls back to the original for the documents that have
                    // no variants: a file uploaded before they existed, or a
                    // format GD cannot re-encode.
                    'publicUrl' => $this->documentUrlGenerator->variantUrl($ogImage, 'large')
                        ?? $this->documentUrlGenerator->publicUrl($ogImage),
                    // The document's own description. There is no per-post
                    // one, and adding a field for this alone would be a field
                    // to fill on every publication for a string the document
                    // already carries.
                    'alt' => (string) $ogImage->getAlt(),
                    // The document's focal point, for a theme that prints this
                    // picture as a hero. A wide photo in a shallow band crops
                    // to its middle without it, which is where a sky usually
                    // is. Not the post's own focal point - that one belongs to
                    // the thumbnail, and answers a different question: how this
                    // publication should look in a card.
                    'focalPosition' => $this->documentUrlGenerator->focalPositionCss($ogImage),
                ]
                : null,
            'jsonLd' => $translation->getJsonLd(),
            // Same gap the listing cards had: a post type whose meaning lives
            // in its custom fields could not render them on its own page.
            'customFields' => $translation->getCustomFields(),
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
