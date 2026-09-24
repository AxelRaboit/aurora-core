<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Entity\PostInterface;

use function array_values;

use const DATE_ATOM;

/**
 * Ce qu'une révision retient d'une publication.
 *
 * La forme vivait dans une méthode privée du gestionnaire, qui est le seul
 * endroit d'où naît une révision - tant qu'on ne cherche pas à en fabriquer
 * ailleurs. Le jeu de démonstration en a eu besoin : il écrit ses
 * publications en direct, donc aucune révision n'existait, et la modale
 * « Historique des versions » s'ouvrait sur un écran vide. C'est précisément
 * la fonction que la page publique du module promet.
 *
 * Sortie ici plutôt que recopiée dans les fixtures : deux définitions de ce
 * qu'une révision contient dériveraient, et la seconde ne se verrait qu'au
 * moment d'une restauration - c'est-à-dire le jour où quelqu'un compte
 * dessus.
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
                // Le corps, qui est une grille et vit en deux moitiés : ce que
                // chaque zone porte est ici, l'arrangement est sur la
                // publication plus bas. Prendre l'un sans l'autre restaure des
                // mots sans emplacement, ou un emplacement sans mots.
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
