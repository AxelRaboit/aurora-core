<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Twig\Attribute\AsTwigFilter;

use function array_map;
use function implode;
use function preg_match;
use function preg_replace;
use function preg_split;
use function strtolower;

/**
 * The passages of a template still waiting for their words, lit up.
 *
 * A model is written with its blanks between brackets - « [Nom de la
 * marque] », « [0,7 %] » - and the one thing its author must not do is send it
 * with one left in. Marked only where the author looks before sending (the
 * back-office preview), never on the page the client opens.
 *
 * Works on the rendered markup, on text only: tags are left alone, and so is
 * whatever sits inside `<script>`, `<style>` or `<textarea>`, where a bracket
 * is code rather than a blank.
 */
final readonly class PlaceholderMarkExtension
{
    private const string BLANK = '/\[([^\[\]<>\n]{1,300})\]/u';

    #[AsTwigFilter('mark_placeholders', isSafe: ['html'])]
    public function mark(string $html): string
    {
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $raw = null;

        return implode('', array_map(function (string $part) use (&$raw): string {
            if (1 === preg_match('/^<(\/?)(script|style|textarea)\b/i', $part, $tag)) {
                $raw = '' === $tag[1] ? strtolower($tag[2]) : null;

                return $part;
            }

            if (null !== $raw || '' === $part || '<' === $part[0]) {
                return $part;
            }

            return (string) preg_replace(self::BLANK, '<mark class="aurora-placeholder">[$1]</mark>', $part);
        }, $parts));
    }
}
