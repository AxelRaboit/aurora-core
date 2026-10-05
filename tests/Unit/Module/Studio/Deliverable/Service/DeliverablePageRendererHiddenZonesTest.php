<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\Deliverable\Service;

use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Studio\Deliverable\Service\DeliverablePageRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function in_array;

/**
 * What a document handed to a client may not contain.
 *
 * The filter works on the layout, before any zone is resolved: a zone dropped
 * afterwards has already read its publication or its deck.
 */
final class DeliverablePageRendererHiddenZonesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function typesThatDoNotBelongInAClientDocument(): iterable
    {
        foreach (DeliverablePageRenderer::HIDDEN_ZONE_TYPES as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('typesThatDoNotBelongInAClientDocument')]
    public function testEveryHiddenTypeIsDroppedFromTheLayout(string $type): void
    {
        $layout = ['zones' => [['id' => 'a', 'type' => 'text'], ['id' => 'b', 'type' => $type]]];

        $kept = DeliverablePageRenderer::withoutHiddenLayoutZones($layout);

        self::assertSame(['a'], array_column($kept['zones'], 'id'));
    }

    public function testTheListCoversWhatShowsTheStudiosOwnDataOrAnotherPublication(): void
    {
        // The shared block renders another publication, draft included; these
        // three read the studio's integrations, not the client's.
        foreach ([
            GridNormalizer::ZONE_SHARED,
            GridNormalizer::ZONE_GITHUB_ACTIVITY,
            GridNormalizer::ZONE_INSTAGRAM_FEED,
            GridNormalizer::ZONE_GOOGLE_REVIEWS,
            GridNormalizer::ZONE_APPOINTMENT_BOOKING,
        ] as $type) {
            self::assertTrue(in_array($type, DeliverablePageRenderer::HIDDEN_ZONE_TYPES, true), $type);
        }
    }

    public function testHiddenZonesInsideAStackAreDroppedToo(): void
    {
        $layout = ['zones' => [
            ['id' => 'stack', 'type' => 'stack', 'children' => [
                ['id' => 'keep', 'type' => 'media'],
                ['id' => 'drop', 'type' => GridNormalizer::ZONE_SHARED],
            ]],
        ]];

        $kept = DeliverablePageRenderer::withoutHiddenLayoutZones($layout);

        self::assertSame(['keep'], array_column($kept['zones'][0]['children'], 'id'));
    }

    public function testTheContentOfADroppedZoneIsDroppedWithIt(): void
    {
        $layout = DeliverablePageRenderer::withoutHiddenLayoutZones(['zones' => [
            ['id' => 'a', 'type' => 'text'],
            ['id' => 'b', 'type' => GridNormalizer::ZONE_POST_LIST],
            ['id' => 's', 'type' => 'stack', 'children' => [['id' => 'c', 'type' => 'text'], ['id' => 'd', 'type' => GridNormalizer::ZONE_DECK]]],
        ]]);

        $content = DeliverablePageRenderer::contentOfShownZones($layout, ['zones' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 's' => 5], 'other' => 'kept']);

        self::assertSame(['a' => 1, 'c' => 3, 's' => 5], $content['zones']);
        self::assertSame('kept', $content['other']);
    }

    public function testALayoutWithoutZonesIsLeftAlone(): void
    {
        self::assertSame(['enabled' => true], DeliverablePageRenderer::withoutHiddenLayoutZones(['enabled' => true]));
        self::assertSame(['zones' => 'oops'], DeliverablePageRenderer::contentOfShownZones(['zones' => []], ['zones' => 'oops']));
    }

    /**
     * A month of planned posts is written by hand in the zone: it reads no
     * data of the site, and a strategy plans its months with it.
     */
    public function testAMonthCalendarIsKeptInADeliverable(): void
    {
        $layout = DeliverablePageRenderer::withoutHiddenLayoutZones(['enabled' => true, 'zones' => [['id' => 'c1', 'type' => 'editorialCalendar']]]);

        self::assertSame(['c1'], array_column($layout['zones'], 'id'));
        self::assertNotContains('editorialCalendar', DeliverablePageRenderer::HIDDEN_ZONE_TYPES);
    }
}
