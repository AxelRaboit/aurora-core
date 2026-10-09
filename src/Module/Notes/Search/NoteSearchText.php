<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Search;

use Normalizer;

use function array_fill;
use function array_push;
use function array_reverse;
use function array_slice;
use function count;
use function implode;
use function max;
use function mb_str_split;
use function mb_strlen;
use function mb_strpos;
use function mb_strtolower;
use function mb_substr;
use function min;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_replace;
use function usort;

/**
 * The text a search reads (10/10/2026): without accents nor case, so that
 * « echeance » finds « Échéance », and without the Markdown that nobody
 * types in a search box.
 *
 * Folding keeps track of where each folded character came from, so that a
 * match found in the folded text is highlighted on the right letters of the
 * original one.
 */
final class NoteSearchText
{
    /** Lowercase, without diacritics. */
    public static function fold(string $text): string
    {
        return self::foldWithMap($text)[0];
    }

    /**
     * The folded text, and for each of its characters the index of the
     * original character it comes from.
     *
     * @return array{0: string, 1: list<int>}
     */
    public static function foldWithMap(string $text): array
    {
        $folded = '';
        $map = [];
        foreach (mb_str_split($text) as $index => $character) {
            $decomposed = Normalizer::normalize($character, Normalizer::FORM_D);
            $bare = mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', false === $decomposed ? $character : $decomposed));
            if ('' === $bare) {
                continue;
            }

            $folded .= $bare;
            array_push($map, ...array_fill(0, mb_strlen($bare), $index));
        }

        return [$folded, $map];
    }

    /**
     * Every occurrence of the needles in the text, as ranges of the original
     * text: `[start, length]`, merged and in order.
     *
     * @param list<string> $needles already folded
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function ranges(string $text, array $needles): array
    {
        [$folded, $map] = self::foldWithMap($text);
        $ranges = [];
        foreach ($needles as $needle) {
            $length = mb_strlen($needle);
            if (0 === $length) {
                continue;
            }

            $offset = 0;
            while (false !== ($position = mb_strpos($folded, $needle, $offset))) {
                $start = $map[$position];
                $end = $map[$position + $length - 1] + 1;
                $ranges[] = [$start, $end - $start];
                $offset = $position + $length;
            }
        }

        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($last >= 0 && $range[0] <= $merged[$last][0] + $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[0] + $range[1] - $merged[$last][0]);
            } else {
                $merged[] = $range;
            }
        }

        return $merged;
    }

    /**
     * The text with every occurrence of `$find` replaced, and how many there
     * were. Without accents nor case unless `$exact`, as the search finds
     * them: the replacement goes on the original letters it matched.
     *
     * @return array{0: string, 1: int}
     */
    public static function replace(string $text, string $find, string $replacement, bool $exact = false): array
    {
        if ('' === $find) {
            return [$text, 0];
        }

        if ($exact) {
            $replaced = str_replace($find, $replacement, $text, $count);

            return [$replaced, $count];
        }

        $ranges = self::ranges($text, [self::fold($find)]);
        $result = $text;
        foreach (array_reverse($ranges) as [$start, $length]) {
            $result = mb_substr($result, 0, $start).$replacement.mb_substr($result, $start + $length);
        }

        return [$result, count($ranges)];
    }

    /**
     * Up to `$limit` passages of the text around the matches, each with the
     * ranges to highlight inside it.
     *
     * @param list<string> $needles already folded
     *
     * @return list<array{text: string, ranges: list<array{0: int, 1: int}>}>
     */
    public static function snippets(string $text, array $needles, int $limit = 3, int $radius = 70): array
    {
        $ranges = self::ranges($text, $needles);
        if ([] === $ranges) {
            return [];
        }

        $characters = mb_str_split($text);
        $total = count($characters);
        $windows = [];
        foreach ($ranges as [$start, $length]) {
            $from = max(0, $start - $radius);
            $to = min($total, $start + $length + $radius);
            $last = count($windows) - 1;
            if ($last >= 0 && $from <= $windows[$last][1]) {
                $windows[$last][1] = max($windows[$last][1], $to);
                continue;
            }

            if (count($windows) >= $limit) {
                break;
            }

            $windows[] = [$from, $to];
        }

        $snippets = [];
        foreach ($windows as [$from, $to]) {
            // Started and ended on a word, not in the middle of one: a few
            // characters more on each side, never more than a word's length.
            for ($step = 0; $from > 0 && ' ' !== $characters[$from - 1] && $step < 20; ++$step) {
                --$from;
            }

            for ($step = 0; $to < $total && ' ' !== $characters[$to] && $step < 20; ++$step) {
                ++$to;
            }

            $inside = [];
            foreach ($ranges as [$start, $length]) {
                if ($start >= $from && $start + $length <= $to) {
                    $inside[] = [$start - $from + ($from > 0 ? 1 : 0), $length];
                }
            }

            $snippets[] = [
                'text' => ($from > 0 ? '…' : '').implode('', array_slice($characters, $from, $to - $from)).($to < $total ? '…' : ''),
                'ranges' => $inside,
            ];
        }

        return $snippets;
    }

    /**
     * A note's Markdown as the words a reader sees: links as their text,
     * people as their name, no markers, no addresses. Lines are kept; runs of
     * spaces are not.
     */
    public static function plain(string $markdown): string
    {
        $patterns = [
            '/^```.*$/m' => '',
            '/^~~~.*$/m' => '',
            '/!\[\[([^\]|#]*)(?:#[^\]|]*)?(?:\|([^\]]*))?\]\]/u' => '$1 $2',
            '/\[\[([^\]|#]*)(?:#([^\]|]*))?\|([^\]]*)\]\]/u' => '$3',
            '/\[\[([^\]|#]*)(?:#([^\]|]*))?\]\]/u' => '$1 $2',
            '/@\[([^\]\n]{1,80})\]\(user:\d{1,10}\)/u' => '@$1',
            '/!\[([^\]]*)\]\([^)]*\)/u' => '$1',
            '/\[([^\]]*)\]\([^)]*\)/u' => '$1',
            '/<[^>]+>/u' => ' ',
            '/^\s{0,3}#{1,6}\s+/m' => '',
            '/^\s*>\s?\[![^\]]+\][+-]?\s*/m' => '',
            '/^\s*>\s?/m' => '',
            '/^\s*[-*+]\s+\[[ xX]\]\s*/m' => '',
            '/^\s*(?:[-*+]|\d+[.)])\s+/m' => '',
            '/\{[a-z]+\}|\{\/\}/u' => '',
            '/==/u' => '',
            '/(\*\*|__|~~|`)/u' => '',
            '/(?<![\p{L}\p{N}])[*_](?=\S)|(?<=\S)[*_](?![\p{L}\p{N}])/u' => '',
            '/\[\^[^\]]+\]:?/u' => '',
            '/\s\^[A-Za-z0-9-]+$/m' => '',
            '/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/m' => '',
            '/\|/u' => ' ',
            '/\[toc\]\]?|\[\[toc\]\]|\[TOC\]/u' => '',
            '/[ \t]{2,}/u' => ' ',
            '/\n{3,}/u' => "\n\n",
        ];

        $text = $markdown;
        foreach ($patterns as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return mb_substr($text, 0, 200_000);
    }

    /**
     * The headings of a note, as words.
     *
     * @return list<string>
     */
    public static function headings(string $markdown): array
    {
        $headings = [];
        $fence = false;
        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (1 === preg_match('/^(```|~~~)/', $line)) {
                $fence = !$fence;
                continue;
            }

            if (!$fence && 1 === preg_match('/^\s{0,3}#{1,6}\s+(.+)$/u', $line, $match)) {
                $headings[] = self::plain($match[1]);
            }
        }

        return $headings;
    }
}
