<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Seo\Service;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermTranslationInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the hreflang alternates a page declares.
 *
 * A locale is only listed when the thing actually exists there. Pointing
 * hreflang at a URL that 404s is worse than saying nothing: it tells a
 * search engine a translation exists and then fails to serve it.
 */
final readonly class AlternatesBuilder
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private Context $context,
    ) {}

    /** @return list<array{locale: string, url: string}> */
    public function forPost(PostInterface $post): array
    {
        $alternates = [];
        foreach ($this->context->activeLocaleCodes() as $code) {
            $translation = $post->getTranslation($code);
            if (!$translation instanceof PostTranslationInterface) {
                continue;
            }

            $slug = $translation->getSlug();
            if (null === $slug) {
                continue;
            }

            if ('' === $slug) {
                continue;
            }

            $alternates[] = [
                'locale' => $code,
                'url' => $this->urlGenerator->generate('editorial_post', [
                    'locale' => $code,
                    'postTypeSlug' => $post->getPostType()->getSlug(),
                    'slug' => $slug,
                ]),
            ];
        }

        return $alternates;
    }

    /** @return list<array{locale: string, url: string}> */
    public function forTerm(TaxonomyInterface $taxonomy, TaxonomyTermInterface $term): array
    {
        $alternates = [];
        foreach ($this->context->activeLocaleCodes() as $code) {
            $translation = $term->getTranslation($code);
            if (!$translation instanceof TaxonomyTermTranslationInterface) {
                continue;
            }

            if ('' === $translation->getSlug()) {
                continue;
            }

            $alternates[] = [
                'locale' => $code,
                'url' => $this->urlGenerator->generate('editorial_term', [
                    'locale' => $code,
                    'taxonomySlug' => $taxonomy->getSlug(),
                    'termSlug' => $translation->getSlug(),
                ]),
            ];
        }

        return $alternates;
    }

    /**
     * A form is only listed in the locales it was actually translated into —
     * the slug differs per locale, and a locale with no translation has no
     * page to point at.
     *
     * @return list<array{locale: string, url: string}>
     */
    public function forForm(FormInterface $form): array
    {
        $alternates = [];
        foreach ($this->context->activeLocaleCodes() as $code) {
            $translation = $form->getTranslation($code);
            if (!$translation instanceof FormTranslationInterface) {
                continue;
            }

            if ('' === $translation->getSlug()) {
                continue;
            }

            $alternates[] = [
                'locale' => $code,
                'url' => $this->urlGenerator->generate('editorial_form', [
                    'locale' => $code,
                    'slug' => $translation->getSlug(),
                ]),
            ];
        }

        return $alternates;
    }

    /**
     * For pages that exist in every locale by construction — the home page,
     * an archive — where there is no translation to check for.
     *
     * @param array<string, string> $extraParams
     *
     * @return list<array{locale: string, url: string}>
     */
    public function forRoute(string $route, array $extraParams = []): array
    {
        $alternates = [];
        foreach ($this->context->activeLocaleCodes() as $code) {
            $alternates[] = [
                'locale' => $code,
                'url' => $this->urlGenerator->generate($route, [...$extraParams, 'locale' => $code]),
            ];
        }

        return $alternates;
    }
}
