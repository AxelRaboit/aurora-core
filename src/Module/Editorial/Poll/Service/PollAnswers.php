<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Poll\Service;

use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function count;
use function preg_split;
use function round;

/**
 * What a poll offers, and how its votes read, in one place.
 *
 * The page draws the buttons and the vote route checks the position it is
 * sent against the same list: read twice, the two had drifted. An answer
 * written `0` - a fair choice in "0 / 1-2 / 3+" - was a button on the page
 * and nothing to the route, which then refused the last button as closed.
 */
final readonly class PollAnswers
{
    public const int MAX_ANSWERS = 8;

    /**
     * The answers a zone offers, or null when it is not a poll yet: no
     * question, or fewer than two answers.
     *
     * @return list<string>|null
     */
    public function of(string $question, string $code): ?array
    {
        $answers = [];
        foreach (preg_split('/\R/', $code) ?: [] as $line) {
            $line = mb_trim($line);
            if ('' !== $line) {
                $answers[] = $line;
            }
        }

        $answers = array_slice($answers, 0, self::MAX_ANSWERS);

        return '' === mb_trim($question) || count($answers) < 2 ? null : $answers;
    }

    /**
     * Each answer's votes and share of the total, in answer order.
     *
     * @param list<string>    $answers
     * @param array<int, int> $tally   votes by answer position
     *
     * @return array{total: int, answers: list<array{votes: int, percent: int}>}
     */
    public function results(array $answers, array $tally): array
    {
        $total = array_sum($tally);

        return [
            'total' => $total,
            'answers' => array_map(static fn (int $index): array => [
                'votes' => $tally[$index] ?? 0,
                'percent' => 0 === $total ? 0 : (int) round(($tally[$index] ?? 0) / $total * 100),
            ], array_keys($answers)),
        ];
    }
}
