<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use function array_is_list;
use function is_array;
use function is_string;

/**
 * The questions a page answers, told to search engines as well as readers.
 *
 * Read from the grid as the page renders it, not from the stored layout: a
 * zone hidden by its dates or its audience is already gone from that view,
 * and structured data describing answers the page does not show is what
 * search engines penalise.
 *
 * Nothing to fill in. An author who writes a folding list has written the
 * questions and the answers; asking them to type the same pairs again into a
 * JSON field would be asking for two versions that drift apart.
 */
final readonly class FaqStructuredData
{
    /**
     * @param array<string, mixed>|null $grid the view `GridViewBuilder::build()` returns
     *
     * @return array<string, mixed>|null null when the page folds no question with its answer
     */
    public function fromGrid(?array $grid): ?array
    {
        $questions = $this->questions(is_array($grid['zones'] ?? null) ? $grid['zones'] : []);

        if ([] === $questions) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $questions,
        ];
    }

    /**
     * The page's structured data: what the author wrote, and the questions.
     *
     * The author's own block wins over a generated one of the same kind - a
     * hand-written FAQPage was written on purpose and a second would be a
     * duplicate. Otherwise both go out, as the JSON-LD list form, which is
     * one `<script>` still.
     *
     * @param array<int|string, mixed>|null $authored
     * @param array<string, mixed>|null     $faq
     *
     * @return array<int|string, mixed>|null
     */
    public function combine(?array $authored, ?array $faq): ?array
    {
        if (null === $faq || [] === $faq) {
            return $authored;
        }

        if (null === $authored || [] === $authored) {
            return $faq;
        }

        $nodes = array_is_list($authored) ? $authored : [$authored];

        foreach ($nodes as $node) {
            if (is_array($node) && 'FAQPage' === ($node['@type'] ?? null)) {
                return $authored;
            }
        }

        return [...$nodes, $faq];
    }

    /**
     * @param array<int|string, mixed> $zones
     *
     * @return list<array<string, mixed>>
     */
    private function questions(array $zones): array
    {
        $questions = [];

        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            $items = $zone['items'] ?? null;
            if (is_array($items) && 'faq' === ($items['display'] ?? null)) {
                foreach (is_array($items['entries'] ?? null) ? $items['entries'] : [] as $entry) {
                    $question = is_string($entry['title'] ?? null) ? mb_trim($entry['title']) : '';
                    $answer = is_string($entry['description'] ?? null) ? mb_trim($entry['description']) : '';
                    // A question without its answer is a heading, and an
                    // answer without its question answers nothing.
                    if ('' === $question) {
                        continue;
                    }

                    if ('' === $answer) {
                        continue;
                    }

                    $questions[] = [
                        '@type' => 'Question',
                        'name' => $question,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
                    ];
                }
            }

            // A stack draws its children as zones of their own.
            if (is_array($zone['children'] ?? null) && [] !== $zone['children']) {
                $questions = [...$questions, ...$this->questions($zone['children'])];
            }
        }

        return $questions;
    }
}
