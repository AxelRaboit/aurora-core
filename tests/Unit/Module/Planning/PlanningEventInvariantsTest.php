<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Planning\Entity\AbstractPlanning;
use Aurora\Module\Planning\Planning\Entity\Planning;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The three things an event refuses to be.
 *
 * Enforced on the entity rather than only in a DTO, because a form is not the
 * only way in: a fixture writes events, and so will the subscriber that lets
 * another module push a date. Neither goes through validation.
 */
final class PlanningEventInvariantsTest extends TestCase
{
    public function testAnEventCannotEndBeforeItStarts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PlanningEvent())->setSpan(
            new DateTimeImmutable('2026-08-23 14:00'),
            new DateTimeImmutable('2026-08-23 13:00'),
        );
    }

    /**
     * Both ends move together, which is why there is one setter and not two: two
     * would let an event exist, for one statement, with an end before its start -
     * and that is the state a month grid divides by.
     */
    public function testAnEventEndingWhenItStartsIsFine(): void
    {
        $at = new DateTimeImmutable('2026-08-23 14:00');
        $event = (new PlanningEvent())->setSpan($at, $at);

        self::assertSame($at, $event->getStartAt());
        self::assertSame($at, $event->getEndAt());
    }

    /**
     * A type without an id points at nothing and an id without a type points at
     * everything. The unique index on the pair only holds if they arrive
     * together.
     */
    public function testASourceIsBothHalvesOrNeither(): void
    {
        $event = new PlanningEvent();

        $event->setSource(null, null, null);
        self::assertFalse($event->isFromModule());

        $event->setSource('editorial.post', 12, 'Grille 48 colonnes');
        self::assertTrue($event->isFromModule());
        self::assertSame('editorial.post', $event->getSourceType());

        $this->expectException(InvalidArgumentException::class);
        $event->setSource('editorial.post', null, 'orpheline');
    }

    /**
     * A label alone is not a source. It is the one combination that looks
     * harmless: the event renders, says where it came from, and has no source to
     * go back to.
     */
    public function testALabelWithoutAnIdIsNotASource(): void
    {
        $event = (new PlanningEvent())->setSource(null, null, 'Éditorial');

        self::assertFalse($event->isFromModule());
    }

    /**
     * A slot outside the palette draws nothing at all, and a calendar with no
     * colour is invisible on a month grid. Clamped rather than refused: a value
     * arriving from an old payload should land somewhere legible, not raise.
     */
    public function testAColourSlotIsClampedToThePalette(): void
    {
        $planning = new Planning();

        self::assertSame(AbstractPlanning::DEFAULT_COLOUR_SLOT, $planning->getColourSlot());

        $planning->setColourSlot(3);
        self::assertSame(3, $planning->getColourSlot());

        $planning->setColourSlot(0);
        self::assertSame(1, $planning->getColourSlot());

        $planning->setColourSlot(99);
        self::assertSame(AbstractPlanning::MAX_COLOUR_SLOT, $planning->getColourSlot());
    }

    public function testACalendarStartsWithACollectionRatherThanNull(): void
    {
        // `convention_collection_on_concrete`: an uninitialised collection is
        // null, and the first `add()` is a crash nobody sees until a fixture runs.
        self::assertCount(0, (new Planning())->getEvents());
    }
}
