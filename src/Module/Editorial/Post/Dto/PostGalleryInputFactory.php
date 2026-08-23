<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Dto;

use function is_array;

/**
 * Reads a gallery payload out of a request, keeping only the two things it may
 * say.
 *
 * Deliberately not a branch of `PostInputFactory`. That one reads seventeen
 * fields, and a shared factory with a flag would mean the narrow endpoint's safety
 * depended on remembering to pass the flag - the sort of thing that survives one
 * refactor and not two. Two factories that each read exactly what their endpoint
 * accepts cannot drift into accepting more.
 */
final readonly class PostGalleryInputFactory implements PostGalleryInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PostGalleryInput
    {
        $layout = $data['galleryLayout'] ?? [];
        $words = $data['gallery'] ?? [];

        return new PostGalleryInput(
            layout: is_array($layout) ? $layout : [],
            words: $this->wordsByLocale(is_array($words) ? $words : []),
        );
    }

    /**
     * Keeps the locale keys whose value is itself a set of words.
     *
     * A payload shaped `{"fr": "oui"}` is not a mistake worth an error - it is a
     * client sending nonsense - and dropping it leaves the locale with no words,
     * which the normaliser already handles.
     *
     * @param array<mixed> $raw
     *
     * @return array<string, array<string, mixed>>
     */
    private function wordsByLocale(array $raw): array
    {
        $words = [];

        foreach ($raw as $locale => $set) {
            if (is_string($locale) && is_array($set)) {
                $words[$locale] = $set;
            }
        }

        return $words;
    }
}
