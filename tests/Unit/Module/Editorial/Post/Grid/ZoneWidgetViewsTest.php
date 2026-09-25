<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Post\Grid;

use Aurora\Core\Content\ContentValueNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridZoneOptions;
use Aurora\Module\Editorial\Post\Grid\ZoneWidgetViews;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * What the small self-contained zones say, at a moment the test chooses.
 */
final class ZoneWidgetViewsTest extends TestCase
{
    private const array HOURS = [
        'mon' => [['09:00', '12:00'], ['14:00', '18:00']],
        'tue' => [['09:00', '18:00']],
        'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => [],
    ];

    /** 25 September 2026 is a Friday; Monday is the 28th. */
    public function testAShopIsOpenInItsOwnTimezone(): void
    {
        // 13:30 in Paris is 07:30 in Montreal: open in Paris after lunch, and
        // not yet open in Montreal, from the same instant.
        $state = $this->view('2026-09-28 11:30:00 UTC')->build($this->zone('openingHours', ['hours' => self::HOURS]), $this->held(), 'fr', static fn () => null);
        self::assertFalse($state['open']);
        self::assertStringContainsString('opens_at', $state['status']);

        $state = $this->view('2026-09-28 12:30:00 UTC')->build($this->zone('openingHours', ['hours' => self::HOURS]), $this->held(), 'fr', static fn () => null);
        self::assertTrue($state['open']);

        $state = $this->view('2026-09-28 12:30:00 UTC')->build($this->zone('openingHours', ['hours' => self::HOURS, 'timezone' => 'America/Montreal']), $this->held(), 'fr', static fn () => null);
        self::assertFalse($state['open']);
    }

    public function testAClosureTodayPointsToTheNextOpenDay(): void
    {
        $state = $this->view('2026-09-28 08:00:00 UTC')->build(
            $this->zone('openingHours', ['hours' => self::HOURS, 'closedDates' => ['2026-09-28']]),
            $this->held(),
            'fr',
            static fn () => null,
        );

        self::assertTrue($state['closedToday']);
        self::assertFalse($state['open']);
        self::assertStringContainsString('opens_tomorrow', $state['status']);
    }

    public function testAWeekWithNoHoursDrawsNothing(): void
    {
        self::assertNull($this->view('2026-09-28 08:00:00 UTC')->build($this->zone('openingHours'), $this->held(), 'fr', static fn () => null));
    }

    public function testACountdownCountsToAnInstantInTheZonesTimezone(): void
    {
        // 18:30 in Paris on 14 November is 17:30 UTC.
        $view = $this->view('2026-11-13 17:30:00 UTC')->build(
            $this->zone('countdown', ['countdownAt' => '2026-11-14T18:30']),
            $this->held(),
            'fr',
            static fn () => null,
        );

        self::assertSame('2026-11-14T18:30:00+01:00', $view['at']);
        self::assertFalse($view['passed']);
        self::assertSame([1, 0, 0], [$view['days'], $view['hours'], $view['minutes']]);

        $after = $this->view('2026-11-15 00:00:00 UTC')->build($this->zone('countdown', ['countdownAt' => '2026-11-14T18:30']), $this->held(), 'fr', static fn () => null);
        self::assertTrue($after['passed']);
    }

    /** A sentence typed by the author wins over the status's own. */
    public function testAvailabilitySaysItsStatusUnlessTheAuthorDid(): void
    {
        $view = $this->view('2026-09-25 08:00:00 UTC');

        self::assertSame(
            'frontend.editorial.grid.availability.soon_undated',
            $view->build($this->zone('availability', ['availability' => 'soon']), $this->held(), 'fr', static fn () => null)['label'],
        );
        self::assertSame(
            'Libre en novembre',
            $view->build($this->zone('availability'), $this->held(label: 'Libre en novembre'), 'fr', static fn () => null)['label'],
        );
    }

    public function testTheContactFileCarriesTheCardAndEscapesItsSeparators(): void
    {
        $vcard = ZoneWidgetViews::vcard(GridZoneOptions::normalize([
            'contactName' => 'Axel Raboit',
            'contactPhone' => '+33 6 12 34 56 78',
            'contactEmail' => 'axel@example.com',
        ]), 'Développeur; photographe');

        self::assertStringContainsString("FN:Axel Raboit\r\n", $vcard);
        self::assertStringContainsString("N:Raboit;Axel;;;\r\n", $vcard);
        self::assertStringContainsString("TITLE:Développeur\; photographe\r\n", $vcard);
        self::assertStringContainsString("TEL;TYPE=CELL:+33612345678\r\n", $vcard);
        self::assertStringEndsWith('END:VCARD', $vcard);
    }

    public function testASocialPostPrintsItsCountersTheWayNetworksDo(): void
    {
        $view = $this->view('2026-09-25 08:00:00 UTC')->build(
            $this->zone('socialPost', ['socialName' => 'Axel', 'socialLikes' => 12_400, 'socialComments' => 1_234_567, 'socialShares' => 42]),
            $this->held(caption: 'Une séance au lever du soleil.'),
            'fr',
            static fn () => null,
        );

        self::assertSame("12\u{202f}k", $view['likes']);
        self::assertSame("1,2\u{202f}M", $view['comments']);
        self::assertSame('42', $view['shares']);
    }

    private function view(string $now): ZoneWidgetViews
    {
        return new ZoneWidgetViews(new IdentityTranslator(), new MockClock(new DateTimeImmutable($now)));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function zone(string $type, array $options = []): array
    {
        return (new GridNormalizer(new ContentValueNormalizer()))->normalizeLayout([
            'zones' => [['id' => 'z1', 'type' => $type, 'options' => $options]],
        ])['zones'][0];
    }

    /** @return array<string, mixed> */
    private function held(string $label = '', string $caption = ''): array
    {
        return ['blocks' => [], 'alt' => '', 'caption' => $caption, 'url' => null, 'label' => $label, 'code' => '', 'items' => []];
    }
}
