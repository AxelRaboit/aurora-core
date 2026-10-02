<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Configuration\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\Service\SiteDateFormatter;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The site's time and how its dates are written.
 *
 * Both settings were read by nothing. What these tests hold: a time typed
 * without an offset is the site's, a stored instant is printed at the site's
 * time in the reader's language, and a day with no time never moves.
 */
final class SiteTimeTest extends TestCase
{
    public function testABareTimeIsReadAtTheSiteTimeAndStoredInUtc(): void
    {
        $stored = $this->timezone('Europe/Paris')->parseLocal('2026-10-02T09:00');

        self::assertSame('2026-10-02 07:00', $stored?->format('Y-m-d H:i'));
        self::assertSame('UTC', $stored?->getTimezone()->getName());
    }

    public function testATimeWithItsOffsetKeepsIt(): void
    {
        $stored = $this->timezone('America/New_York')->parseLocal('2026-12-01T09:00:00+01:00');

        self::assertSame('2026-12-01 08:00', $stored?->format('Y-m-d H:i'));
    }

    public function testAnUnknownZoneFallsBackToTheDefault(): void
    {
        $timezone = $this->timezone('Mars/Olympus');

        self::assertSame(ApplicationParameterEnum::Timezone->getDefaultValue(), $timezone->name());
    }

    public function testAnInstantIsWrittenAtTheSiteTimeInTheReadersLanguage(): void
    {
        $signedAt = new DateTimeImmutable('2026-10-02 09:14:03', new DateTimeZone('UTC'));
        $formatter = $this->formatter('Europe/Paris', 'short');

        self::assertSame('02/10/2026', $formatter->date($signedAt, 'fr'));
        self::assertSame('2/10/2026', $formatter->date($signedAt, 'es'));
        self::assertSame('02/10/2026 11:14:03 (Europe/Paris)', $formatter->dateTime($signedAt, 'fr', true));
    }

    public function testTheStyleIsTheSettingsAndTheOldPatternReadsAsShort(): void
    {
        $date = new DateTimeImmutable('2026-10-02 12:00', new DateTimeZone('UTC'));

        self::assertSame('2 octobre 2026', $this->formatter('Europe/Paris', 'long')->date($date, 'fr'));
        self::assertSame('short', $this->formatter('Europe/Paris', 'd/m/Y')->style());
    }

    public function testADayWithNoTimeNeverMoves(): void
    {
        // A declared date, stored as midnight. West of Greenwich, reading it
        // as an instant would print the day before.
        $declared = new DateTimeImmutable('2026-10-02 00:00', new DateTimeZone('UTC'));

        self::assertSame('02/10/2026', $this->formatter('America/New_York', 'short')->calendarDate($declared, 'fr'));
    }

    private function timezone(string $zone): SiteTimezone
    {
        return new SiteTimezone($this->settings([ApplicationParameterEnum::Timezone->value => $zone]));
    }

    private function formatter(string $zone, string $style): SiteDateFormatter
    {
        $settings = $this->settings([
            ApplicationParameterEnum::Timezone->value => $zone,
            ApplicationParameterEnum::DateFormat->value => $style,
        ]);

        return new SiteDateFormatter($settings, new SiteTimezone($settings), new LocaleSwitcher('fr', []));
    }

    /** @param array<string, string> $values */
    private function settings(array $values): SettingRepository
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('getOrDefault')->willReturnCallback(
            static fn (ApplicationParameterEnum $parameter): string => $values[$parameter->value] ?? $parameter->getDefaultValue(),
        );

        return $repository;
    }
}
