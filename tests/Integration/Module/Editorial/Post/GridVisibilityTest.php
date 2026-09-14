<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Post\Grid\GridViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;

/**
 * When a zone is drawn, and for whom.
 *
 * Not a zone type: a field every one of them carries, which is what makes it
 * worth its two notches of cost. The cases below are the ones an author
 * actually reaches for - a promotion that ends on a date, a tariff for
 * members - plus the two boundaries, because "until the 31st" has to include
 * the 31st or nobody can express what they meant.
 *
 * The editor is the other half and is checked here too: a zone waiting for its
 * date must stay in the panel, or an author cannot arrange what they wrote.
 */
final class GridVisibilityTest extends IntegrationTestCase
{
    private GridViewBuilder $gridViewBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->gridViewBuilder = static::getContainer()->get(GridViewBuilder::class);
    }

    public function testAZoneWithNoLimitsIsDrawn(): void
    {
        self::assertSame(['z1'], $this->visibleIds([['id' => 'z1', 'type' => 'separator']]));
    }

    public function testAZoneIsWithheldUntilItsDay(): void
    {
        self::assertSame([], $this->visibleIds([[
            'id' => 'z1',
            'type' => 'separator',
            'visibleFrom' => $this->day('+2 days'),
        ]]));
    }

    public function testAZoneStopsBeingDrawnAfterItsDay(): void
    {
        self::assertSame([], $this->visibleIds([[
            'id' => 'z1',
            'type' => 'separator',
            'visibleUntil' => $this->day('-1 day'),
        ]]));
    }

    /**
     * Both bounds are inclusive. "From the 1st until the 31st" has to mean
     * both of those days are days the zone is drawn - it is what the words
     * say, and an author has no other way to express it.
     */
    public function testBothBoundsIncludeTheirOwnDay(): void
    {
        self::assertSame(['z1'], $this->visibleIds([[
            'id' => 'z1',
            'type' => 'separator',
            'visibleFrom' => $this->day('today'),
            'visibleUntil' => $this->day('today'),
        ]]));
    }

    /** A visitor nobody has signed in is not a member. */
    public function testAMembersZoneIsWithheldFromAnAnonymousVisitor(): void
    {
        self::assertSame([], $this->visibleIds([[
            'id' => 'z1',
            'type' => 'separator',
            'audience' => 'members',
        ]]));
    }

    /**
     * The editor sees everything. An author cannot arrange what the panel
     * hides from them, and a zone waiting for its date has to stay editable
     * until it arrives.
     */
    public function testTheEditorIsShownWhatThePageWithholds(): void
    {
        $zones = [
            ['id' => 'z1', 'type' => 'separator', 'visibleFrom' => $this->day('+2 days')],
            ['id' => 'z2', 'type' => 'separator', 'audience' => 'members'],
        ];

        self::assertSame([], $this->visibleIds($zones));

        $forEditor = $this->gridViewBuilder->buildForEditor(
            ['enabled' => true, 'zones' => $zones],
            ['zones' => []],
            'fr',
        );

        self::assertSame(['z1', 'z2'], array_column($forEditor['zones'], 'id'));
    }

    /** A zone inside a stack is withheld on its own, not with the stack. */
    public function testOneZoneOfAStackCanBeWithheldWithoutTheOthers(): void
    {
        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => [[
                'id' => 'z1',
                'type' => 'stack',
                'children' => [
                    ['id' => 'c1', 'type' => 'separator'],
                    ['id' => 'c2', 'type' => 'separator', 'visibleUntil' => $this->day('-1 day')],
                ],
            ]]],
            ['zones' => []],
            'fr',
        );

        self::assertNotNull($grid);
        self::assertSame(['c1'], array_column($grid['zones'][0]['children'], 'id'));
    }

    /**
     * A date the editor could not have produced is no date at all, which reads
     * as "no limit". The alternative is a zone that disappears because
     * somebody typed a month into a day.
     */
    public function testAnUnreadableDateIsNoLimit(): void
    {
        self::assertSame(['z1'], $this->visibleIds([[
            'id' => 'z1',
            'type' => 'separator',
            'visibleUntil' => '31/12/2020',
        ]]));
    }

    private function day(string $modifier): string
    {
        return new DateTimeImmutable($modifier)->format('Y-m-d');
    }

    /**
     * @param list<array<string, mixed>> $zones
     *
     * @return list<string>
     */
    private function visibleIds(array $zones): array
    {
        $grid = $this->gridViewBuilder->build(
            ['enabled' => true, 'zones' => $zones],
            ['zones' => []],
            'fr',
        );

        return null === $grid ? [] : array_column($grid['zones'], 'id');
    }
}
