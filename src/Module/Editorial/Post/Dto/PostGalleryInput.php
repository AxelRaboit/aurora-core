<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Dto;

/**
 * A change to one publication's gallery, and nothing else about it.
 *
 * **This class is the security boundary**, and the reason the gallery screen is
 * not simply the editor opened on a tab. `PostInput` carries seventeen fields -
 * status, type, taxonomy terms, translations, thumbnail, the banner and grid
 * layouts, SEO - and one endpoint accepts all of them. Sending somebody to
 * `/posts/4/edit#gallery` selects a tab in their browser and restricts nothing:
 * they can open any other tab, and the save writes the whole post. A contributor
 * brought in for the pictures could publish a draft or rewrite the SEO.
 *
 * So the restriction is here, in what can be expressed. Two fields, because a
 * gallery is two things:
 *
 * - `$layout` - which pictures, in what order, and the rule that lays them out.
 *   One per publication: a gallery is designed once and every language inherits
 *   it.
 * - `$words` - the alt text and captions, one set per locale, keyed by the id of
 *   the item in the layout.
 *
 * Both arrive raw and are normalised at the write boundary by `GalleryNormalizer`,
 * the same one the full editor goes through. Nothing here is trusted for being
 * well-formed; it is trusted for being *small*.
 */
final readonly class PostGalleryInput
{
    /**
     * @param array<string, mixed>                $layout raw; normalised by GalleryNormalizer
     * @param array<string, array<string, mixed>> $words  raw, keyed by locale; likewise
     */
    public function __construct(
        public array $layout = [],
        public array $words = [],
    ) {}

    /** @return array<string, mixed> */
    public function getLayout(): array
    {
        return $this->layout;
    }

    /**
     * The words for one locale, or nothing when that language was not sent.
     *
     * Absent and empty are the same answer on purpose: a locale the screen did not
     * show has no opinion about captions, and normalising an empty set against the
     * layout is what drops words whose picture has gone.
     *
     * @return array<string, mixed>
     */
    public function wordsFor(string $locale): array
    {
        // No shape check: the factory is what turns a request into this type, and
        // it drops any locale whose value is not a set of words. Re-testing here
        // would be guarding against a caller that constructed the DTO by hand with
        // the wrong types - which the signature already forbids.
        return $this->words[$locale] ?? [];
    }
}
