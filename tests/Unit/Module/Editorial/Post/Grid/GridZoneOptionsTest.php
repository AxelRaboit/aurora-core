<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Grid;

use Aurora\Module\Editorial\Post\Grid\GridZoneOptions;
use PHPUnit\Framework\TestCase;

/**
 * The settings one kind of zone carries, normalised on the way in.
 *
 * Several of them end up in an address, a `tel:` link or a clock the page
 * trusts, so what is worth holding is that nothing but a well-formed value
 * comes out, whatever was stored.
 */
final class GridZoneOptionsTest extends TestCase
{
    public function testAZoneArrivesWithEveryOption(): void
    {
        self::assertSame(GridZoneOptions::defaults(), GridZoneOptions::normalize(null));
    }

    public function testAnUnknownChoiceFallsBackToTheFirst(): void
    {
        $options = GridZoneOptions::normalize(['frame' => 'tablet', 'galleryLayout' => 'carousel', 'codeStyle' => 'terminal']);

        self::assertSame('none', $options['frame']);
        self::assertSame('carousel', $options['galleryLayout']);
        self::assertSame('terminal', $options['codeStyle']);
    }

    /** A range that closes before it opens is dropped, never swapped. */
    public function testOpeningHoursKeepOnlyRealRangesInOrder(): void
    {
        $hours = GridZoneOptions::normalize(['hours' => [
            'mon' => [['14:00', '18:00'], ['09:00', '12:00']],
            'tue' => [['18:00', '09:00'], ['25:00', '26:00'], ['bad']],
            'fri' => [['18:00', '24:00']],
        ]])['hours'];

        self::assertSame([['09:00', '12:00'], ['14:00', '18:00']], $hours['mon']);
        self::assertSame([], $hours['tue']);
        self::assertSame([['18:00', '24:00']], $hours['fri']);
        self::assertSame(GridZoneOptions::WEEKDAYS, array_keys($hours));
    }

    public function testDatesMustBeCalendarDays(): void
    {
        $options = GridZoneOptions::normalize([
            'availableFrom' => '2026-02-30',
            'closedDates' => ['2026-12-25', '2026-12-25', 'demain', '2026-01-01'],
            'countdownAt' => '2026-11-14T18:30',
        ]);

        self::assertNull($options['availableFrom']);
        self::assertSame(['2026-01-01', '2026-12-25'], $options['closedDates']);
        self::assertSame('2026-11-14T18:30', $options['countdownAt']);
        self::assertNull(GridZoneOptions::normalize(['countdownAt' => '2026-11-14 18:30'])['countdownAt']);
    }

    public function testAnUnknownTimezoneIsParis(): void
    {
        self::assertSame('America/Montreal', GridZoneOptions::normalize(['timezone' => 'America/Montreal'])['timezone']);
        self::assertSame('Europe/Paris', GridZoneOptions::normalize(['timezone' => 'Mars/Olympus'])['timezone']);
    }

    /** What ends up in `tel:`, `mailto:` and `href` is what those can carry. */
    public function testContactDetailsThatCannotBeLinksAreDropped(): void
    {
        $options = GridZoneOptions::normalize([
            'contactPhone' => '+33 6 12 34 56 78',
            'contactEmail' => 'axel@example',
            'contactWebsite' => 'javascript:alert(1)',
        ]);

        self::assertSame('+33 6 12 34 56 78', $options['contactPhone']);
        self::assertSame('', $options['contactEmail']);
        self::assertNull($options['contactWebsite']);
        self::assertSame('', GridZoneOptions::normalize(['contactPhone' => '06"><script>'])['contactPhone']);
    }

    public function testRepositoriesAreOwnerSlashName(): void
    {
        self::assertSame(
            ['AxelRaboit/aurora-core', 'AxelRaboit/aurora-client'],
            GridZoneOptions::normalize(['githubRepos' => [
                'AxelRaboit/aurora-core', 'axelraboit/aurora-core', 'AxelRaboit/aurora-client',
                'AxelRaboit/../admin', 'no-slash', 'AxelRaboit/.hidden',
            ]])['githubRepos'],
        );
    }

    public function testAHandleLosesItsAtAndCountersTheirSign(): void
    {
        $options = GridZoneOptions::normalize(['socialHandle' => '@axelraboit', 'socialLikes' => -4, 'socialComments' => '12']);

        self::assertSame('axelraboit', $options['socialHandle']);
        self::assertSame(0, $options['socialLikes']);
        self::assertSame(12, $options['socialComments']);
    }
}
