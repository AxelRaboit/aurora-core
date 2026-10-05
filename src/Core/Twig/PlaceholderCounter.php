<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use function array_map;
use function array_sum;
use function is_array;
use function is_string;
use function preg_match_all;
use function preg_replace;

/**
 * How many [passages à remplacer] a value still holds.
 *
 * The server twin of `countPlaceholders` in the editor, and of
 * {@see PlaceholderMarkExtension}, which lights the same blanks: one
 * definition ({@see PlaceholderMarkExtension::BLANK}), so the number the
 * author sees, the passages lit in the preview and the count a guard checks
 * before opening a document to a client cannot disagree.
 *
 * Markup is stripped first, so a bracket split by a `<b>` still counts once.
 * Keys are not read: a field name is never a blank.
 */
final readonly class PlaceholderCounter
{
    public function count(mixed $value): int
    {
        if (is_string($value)) {
            return (int) preg_match_all(PlaceholderMarkExtension::BLANK, (string) preg_replace('/<[^>]*>/', '', $value));
        }

        if (is_array($value)) {
            return array_sum(array_map($this->count(...), $value));
        }

        return 0;
    }
}
