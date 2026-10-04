<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Grid;

use Aurora\Module\Editorial\Post\Grid\GridSlides;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;

final class GridSlidesTest extends TestCase
{
    public function testEachMainHeadingOpeningARowOpensASlideAndWhatComesFirstIsTheCover(): void
    {
        $zones = [
            ['id' => 'cover', 'type' => 'text', 'span' => ['lg' => 48]],
            ['id' => 's1', 'type' => 'text', 'span' => ['lg' => 48]],
            ['id' => 'card', 'type' => 'text', 'span' => ['lg' => 12]],
            ['id' => 'phone', 'type' => 'media', 'span' => ['lg' => 20]],
            ['id' => 'stat', 'type' => 'text', 'span' => ['lg' => 16]],
            ['id' => 's2', 'type' => 'text', 'span' => ['lg' => 48]],
        ];
        $content = ['zones' => [
            'cover' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Couverture']]]],
            's1' => ['blocks' => [['type' => 'label', 'data' => ['text' => 'x']], ['type' => 'header', 'data' => ['text' => 'Objectifs', 'level' => 2]]]],
            'card' => ['blocks' => [['type' => 'header', 'data' => ['text' => 'Pas une section', 'level' => 3]]]],
            // A main heading in a card that does not start its row is a figure.
            'stat' => ['blocks' => [['type' => 'header', 'data' => ['text' => '0,7 %', 'level' => 2]]]],
            's2' => ['blocks' => [['type' => 'header', 'data' => ['text' => 'Benchmark', 'level' => 2]]]],
        ]];

        $slides = new GridSlides()->split($zones, $content);

        self::assertSame(
            [['cover'], ['s1', 'card', 'phone', 'stat'], ['s2']],
            array_map(static fn (array $slide): array => array_column($slide, 'id'), $slides),
        );
    }

    /** A heading in a card that opens its row starts a section, wide or not. */
    public function testAHeadingInACardOpeningItsRowStartsASection(): void
    {
        $zones = [
            ['id' => 'a', 'type' => 'text', 'span' => ['lg' => 48]],
            ['id' => 'toc', 'type' => 'text', 'span' => ['lg' => 26], 'newRow' => true],
            ['id' => 'pic', 'type' => 'media', 'span' => ['lg' => 22]],
        ];
        $content = ['zones' => [
            'a' => ['blocks' => [['type' => 'header', 'data' => ['text' => 'Objectifs', 'level' => 2]]]],
            'toc' => ['blocks' => [['type' => 'header', 'data' => ['text' => 'Sommaire', 'level' => 2]]]],
        ]];

        self::assertSame(
            [['a'], ['toc', 'pic']],
            array_map(static fn (array $slide): array => array_column($slide, 'id'), new GridSlides()->split($zones, $content)),
        );
    }

    /** Each slide is placed from its own first row, not from where it sat in the page. */
    public function testEachSlideStartsAtItsOwnFirstRow(): void
    {
        $zones = [
            ['id' => 'a', 'type' => 'text', 'span' => ['lg' => 48]],
            ['id' => 'b', 'type' => 'text', 'span' => ['lg' => 48]],
            ['id' => 'c', 'type' => 'text', 'span' => ['lg' => 48]],
        ];
        $heading = static fn (string $text): array => ['blocks' => [['type' => 'header', 'data' => ['text' => $text, 'level' => 2]]]];

        $slides = new GridSlides()->split($zones, ['zones' => ['b' => $heading('B'), 'c' => $heading('C')]]);

        self::assertSame('--row-lg: 1; --start-lg: 1;', $slides[2][0]['startStyle']);
    }

    public function testAGridWithoutHeadingsIsOneSlide(): void
    {
        $slides = new GridSlides()->split([['id' => 'a', 'type' => 'text'], ['id' => 'b', 'type' => 'media']], ['zones' => []]);

        self::assertCount(1, $slides);
    }
}
