<?php

declare(strict_types=1);

namespace Aurora\Module\PersonalFinance\Categorization\Support;

use Normalizer;

/**
 * Normalises a transaction description into the canonical lookup
 * pattern used by PersonalFinanceCategorizationRule:
 *   - strip accents via Unicode NFD decomposition + combining-mark removal
 *   - lowercase
 *   - collapse whitespace.
 *
 * Identical normalisation must apply to both write (learn) and read
 * (suggest) paths — extracted to a single helper to keep them in sync.
 *
 * Uses Normalizer (ext-intl or symfony/polyfill-intl-normalizer) instead
 * of iconv ASCII//TRANSLIT: the latter's transliteration table is provided
 * by the system's libc and produces different output across platforms
 * (e.g. "é" becomes "e" on Linux/glibc but "'e'" with libiconv on macOS).
 *
 * Returns null when the result is empty (no rule should ever be
 * keyed on an empty string).
 */
final class PatternNormalizer
{
    private function __construct() {}

    public static function normalize(?string $description): ?string
    {
        if (null === $description || '' === $description) {
            return null;
        }

        $decomposed = Normalizer::normalize($description, Normalizer::FORM_D);
        $ascii = is_string($decomposed) ? preg_replace('/\p{Mn}/u', '', $decomposed) : $description;
        $ascii = is_string($ascii) ? $ascii : $description;

        $pattern = mb_strtolower(mb_trim($ascii));
        $pattern = (string) preg_replace('/\s+/u', ' ', $pattern);

        return '' === $pattern ? null : $pattern;
    }
}
