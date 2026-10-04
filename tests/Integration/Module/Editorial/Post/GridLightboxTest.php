<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;

/**
 * The pictures of a grid, numbered for the overlay that enlarges them.
 *
 * The overlay is mounted once for the whole grid and steps from one picture to
 * the next, so the order it is handed and the number written on each zone have
 * to be the same order - including a stack's children, which are read where
 * they are drawn.
 */
final class GridLightboxTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->gridViewBuilder = self::getContainer()->get(GridViewBuilder::class);
    }

    public function testEveryPictureIsNumberedInTheOrderItIsRead(): void
    {
        $grid = $this->gridViewBuilder->build(
            [
                'enabled' => true,
                'zones' => [
                    ['id' => 'z1', 'type' => 'media', 'mediaUrl' => 'https://example.com/un.jpg'],
                    ['id' => 'z2', 'type' => 'text'],
                    [
                        'id' => 'z3',
                        'type' => 'stack',
                        'children' => [['id' => 'c1', 'type' => 'media', 'mediaUrl' => 'https://example.com/deux.jpg']],
                    ],
                ],
            ],
            ['zones' => [
                'z1' => ['caption' => 'La première'],
                'z2' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Entre les deux.']]]],
            ]],
            'fr',
        );

        self::assertNotNull($grid);
        self::assertCount(2, $grid['lightbox']);
        self::assertSame('https://example.com/un.jpg', $grid['lightbox'][0]['url']);
        self::assertSame('La première', $grid['lightbox'][0]['caption']);
        self::assertSame('https://example.com/deux.jpg', $grid['lightbox'][1]['url']);

        self::assertSame(0, $grid['zones'][0]['lightboxIndex']);
        self::assertNull($grid['zones'][1]['lightboxIndex'], 'a text zone has no picture to enlarge');
        self::assertSame(1, $grid['zones'][2]['children'][0]['lightboxIndex']);
    }

    /** Nothing to mount on a page with no picture in its grid. */
    public function testAGridWithNoPictureAsksForNoOverlay(): void
    {
        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [['id' => 'z1', 'type' => 'text']]],
            ['zones' => ['z1' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Rien à voir.']]]]]],
            'fr',
        );

        self::assertNotNull($grid);
        self::assertSame([], $grid['lightbox']);
    }
}
