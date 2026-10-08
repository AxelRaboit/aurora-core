<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Contract\Service\ContractVariableResolver;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use Symfony\Component\Translation\LocaleSwitcher;
use Throwable;

/**
 * Dates as the site writes them in mails, PDFs and public pages: in the
 * reader's language, at the site's time, in the style picked under
 * Localisation > Date format.
 *
 * **A style, not a pattern.** The setting used to hold `d/m/Y`, which nothing
 * read, and which could not have served three languages anyway: a Spanish mail
 * and an English one do not write a date the same way. ICU knows how each
 * language writes a short, medium or long date; the setting only picks which.
 * The short style keeps a four-digit year whatever the locale does, like the
 * contract variables ({@see ContractVariableResolver}).
 *
 * The back office keeps its own compact formats (`useDateFormat.js`): a table
 * column does not grow because somebody picked the long style for the mails.
 */
final readonly class SiteDateFormatter
{
    public const array STYLES = ['short', 'medium', 'long'];

    // `full` is not one of the setting's choices: it is asked for by name,
    // where a day is the whole title (a daily note: "jeudi 8 octobre 2026").
    private const array ICU_STYLES = [
        'short' => IntlDateFormatter::SHORT,
        'medium' => IntlDateFormatter::MEDIUM,
        'long' => IntlDateFormatter::LONG,
        'full' => IntlDateFormatter::FULL,
    ];

    public function __construct(
        private SettingRepository $settings,
        private SiteTimezone $timezone,
        private LocaleSwitcher $localeSwitcher,
    ) {}

    /**
     * The chosen style. Anything else, including the `d/m/Y` the setting
     * shipped with, reads as the short style it described.
     */
    public function style(): string
    {
        try {
            $value = $this->settings->getOrDefault(ApplicationParameterEnum::DateFormat);
        } catch (Throwable) {
            return 'short';
        }

        return in_array($value, self::STYLES, true) ? $value : 'short';
    }

    /** An instant's day, at the site's time. */
    public function date(DateTimeInterface $date, ?string $locale = null, ?string $style = null): string
    {
        return $this->format($date, $locale, $style ?? $this->style(), IntlDateFormatter::NONE, $this->timezone->get());
    }

    /**
     * An instant's day and time, at the site's time.
     *
     * `$proof` is for the timestamps a signature rests on: seconds, and the
     * zone they are read in, so nobody has to guess a decade later.
     */
    public function dateTime(DateTimeInterface $date, ?string $locale = null, bool $proof = false): string
    {
        $zone = $this->timezone->get();
        $text = $this->format($date, $locale, $this->style(), $proof ? IntlDateFormatter::MEDIUM : IntlDateFormatter::SHORT, $zone);

        return $proof ? sprintf('%s (%s)', $text, $zone->getName()) : $text;
    }

    /**
     * A calendar day: a date column with no time, such as the date a signer
     * declares. It names a day, not an instant, so no zone moves it - read at
     * the site's time, midnight UTC would turn into the day before west of
     * Greenwich.
     */
    public function calendarDate(DateTimeInterface $date, ?string $locale = null): string
    {
        return $this->format($date, $locale, $this->style(), IntlDateFormatter::NONE, $date->getTimezone());
    }

    private function format(DateTimeInterface $date, ?string $locale, string $style, int $timeStyle, DateTimeZone $zone): string
    {
        $locale ??= $this->localeSwitcher->getLocale();
        $dateStyle = self::ICU_STYLES[$style] ?? IntlDateFormatter::SHORT;

        $formatter = new IntlDateFormatter($locale, $dateStyle, $timeStyle, $zone);
        if (IntlDateFormatter::SHORT === $dateStyle) {
            $formatter->setPattern($this->fourDigitYear((string) $formatter->getPattern()));
        }

        return (string) $formatter->format($date);
    }

    /** ICU's short date gives a two-digit year in several locales. */
    private function fourDigitYear(string $pattern): string
    {
        if (1 === preg_match('/y{4}/', $pattern)) {
            return $pattern;
        }

        return (string) preg_replace('/y{1,3}/', 'yyyy', $pattern);
    }
}
