<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Grid;

use function array_key_last;
use function array_map;
use function is_array;
use function is_string;
use function mb_trim;
use function strip_tags;

/**
 * A grid cut into slides, one per section: what a document shown as a
 * presentation is made of.
 *
 * A section opens on a text zone that starts a row and carries a main
 * heading - the same rule as the editor's outline, so what the author sees
 * listed there is what the reader turns through. A big figure set as a
 * heading in a card beside others does not start its row, and stays put. Whatever comes before the first heading is a
 * slide of its own: the cover.
 */
final class GridSlides
{
    /**
     * The zones of a built grid, grouped by slide, in order.
     *
     * @param list<array<string, mixed>> $zones   the grid's view zones, each with its `id`
     * @param array<string, mixed>       $content what each zone holds, `['zones' => [id => ...]]`
     *
     * @return list<list<array<string, mixed>>>
     */
    public function split(array $zones, array $content): array
    {
        $held = is_array($content['zones'] ?? null) ? $content['zones'] : [];
        $places = GridNormalizer::place($zones);
        $slides = [];

        foreach ($zones as $index => $zone) {
            $opensRow = 1 === ($places[$index]['column'] ?? 1);
            if ([] === $slides || ($opensRow && $this->opensSection($zone, $held[$zone['id'] ?? ''] ?? null))) {
                $slides[] = [];
            }

            $slides[array_key_last($slides)][] = $zone;
        }

        // Placed again from the slide's own first row: kept at the rows they
        // had in the whole page, a later slide would open on a stack of empty
        // rows, one row gap each.
        return array_map(static function (array $slide): array {
            foreach (GridNormalizer::place($slide) as $index => $place) {
                $slide[$index]['startStyle'] = GridViewBuilder::startStyle($place['row'], $place['column']);
            }

            return $slide;
        }, $slides);
    }

    /** @param array<string, mixed> $zone */
    private function opensSection(array $zone, mixed $held): bool
    {
        if ('text' !== ($zone['type'] ?? null) || !is_array($held)) {
            return false;
        }

        foreach (is_array($held['blocks'] ?? null) ? $held['blocks'] : [] as $block) {
            if (!is_array($block)) {
                continue;
            }

            if ('header' !== ($block['type'] ?? null)) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $text = is_string($data['text'] ?? null) ? mb_trim(strip_tags($data['text'])) : '';

            if ('' !== $text && 2 === (int) ($data['level'] ?? 2)) {
                return true;
            }
        }

        return false;
    }
}
