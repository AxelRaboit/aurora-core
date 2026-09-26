<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use function array_filter;
use function count;
use function max;
use function mb_trim;
use function preg_split;
use function round;

/**
 * How long a post takes to read, off the same text the search index already
 * flattens a grid into.
 *
 * `PostTextExtractor::textFromGrid()` is the one place that already knows how
 * to walk a stored grid for its words - written for search, but "every word
 * an author put in this page" is exactly what a reading time needs too.
 * A second walker here would drift from it the day a zone type changed how
 * it stores text.
 */
final readonly class ReadingTimeCalculator
{
    /**
     * A commonly cited average for silent reading of ordinary prose. Not
     * tuned per language: the point is a rough "a few minutes" figure, not a
     * timer to hold anyone to.
     */
    private const int WORDS_PER_MINUTE = 200;

    public function __construct(
        private PostTextExtractor $textExtractor,
    ) {}

    /** @param array<string, mixed> $rawGrid the translation's own stored grid */
    public function minutesFor(array $rawGrid): int
    {
        $text = mb_trim($this->textExtractor->textFromGrid($rawGrid));

        if ('' === $text) {
            return 0;
        }

        $words = count(array_filter((array) preg_split('/\s+/u', $text)));

        // Zero stays zero - a post with nothing to read is not "under a
        // minute", it is not an article. Anything above zero rounds up to at
        // least one: "0 min de lecture" would read as a bug, not as brief.
        return $words > 0 ? max(1, (int) round($words / self::WORDS_PER_MINUTE)) : 0;
    }
}
