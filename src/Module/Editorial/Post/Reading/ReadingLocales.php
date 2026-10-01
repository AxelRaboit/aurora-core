<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Reading;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Post\Entity\PostInterface;

use function in_array;
use function is_string;

/**
 * Which language a publication read outside the site opens in.
 *
 * Shared by every page that does: a reading link, and the documents a client
 * reads in their space. Both answer the same way - the language asked for when
 * the publication is written in it, else the site's default, else the first
 * it has - so a link opens on *something* rather than on an error.
 */
final readonly class ReadingLocales
{
    public function __construct(private LocaleContextInterface $localeContext) {}

    public function pick(PostInterface $post, mixed $requested): ?string
    {
        $available = $this->writtenIn($post);

        if ([] === $available) {
            return null;
        }

        if (is_string($requested) && in_array($requested, $available, true)) {
            return $requested;
        }

        $default = $this->localeContext->getDefaultLocale();

        return in_array($default, $available, true) ? $default : $available[0];
    }

    /** @return list<string> the languages it has a title in */
    public function writtenIn(PostInterface $post): array
    {
        $codes = [];

        foreach ($post->getTranslations() as $translation) {
            if (null !== $translation->getTitle() && '' !== $translation->getTitle()) {
                $codes[] = $translation->getLocale();
            }
        }

        return $codes;
    }
}
