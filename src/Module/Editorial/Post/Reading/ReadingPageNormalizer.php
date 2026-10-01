<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Reading;

use function is_array;
use function is_string;
use function mb_substr;
use function mb_trim;

/**
 * How the page a reading link opens introduces itself.
 *
 * That page has none of the site around it - no menu, no footer of links - so
 * the little it does carry is chosen per publication: the site's logo and
 * name, who the document was prepared for, and the date it was last brought
 * up to date. An audit sent to a client reads "Prepared for Maison Durand,
 * updated on 1 October"; a guide handed to anyone may say neither.
 *
 * Every write goes through here, so the column only ever holds this shape.
 * Missing keys take the defaults, which is what a publication that never
 * opened the panel gets.
 */
final class ReadingPageNormalizer
{
    public const int MAX_PREPARED_FOR = 120;

    /**
     * @return array{preparedFor: ?string, showDate: bool, showLogo: bool}
     */
    public function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        $preparedFor = is_string($raw['preparedFor'] ?? null) ? mb_trim($raw['preparedFor']) : '';

        return [
            'preparedFor' => '' === $preparedFor ? null : mb_substr($preparedFor, 0, self::MAX_PREPARED_FOR),
            'showDate' => (bool) ($raw['showDate'] ?? true),
            'showLogo' => (bool) ($raw['showLogo'] ?? true),
        ];
    }
}
