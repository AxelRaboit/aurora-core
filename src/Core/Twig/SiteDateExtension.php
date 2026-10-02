<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Module\Configuration\Setting\Service\SiteDateFormatter;
use Aurora\Module\Configuration\Setting\Service\SiteTimezone;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Dates in templates, through the Localisation settings.
 *
 * Mails, the contract PDF and the public pages used to print `|date('d/m/Y')`:
 * French whatever the mail's language, and in UTC, so a contract signed at
 * 11:14 in Paris read 09:14 in its own PDF. These filters write the date in
 * the language being rendered (a mail runs with its own locale, see
 * MailService), at the site's time, in the chosen style.
 *
 * - `site_date` / `site_datetime`: an instant (`created_at`, `signed_at`, ...).
 * - `site_datetime(proof: true)`: a timestamp a signature rests on, with
 *   seconds and the zone named.
 * - `calendar_date`: a day with no time (a declared date), never moved.
 *
 * A null or empty value prints nothing, so an optional date needs no `if`
 * around it.
 */
final readonly class SiteDateExtension
{
    public function __construct(
        private SiteDateFormatter $formatter,
        private SiteTimezone $timezone,
    ) {}

    #[AsTwigFilter('site_date')]
    public function siteDate(DateTimeInterface|string|null $date, ?string $locale = null): string
    {
        $date = $this->instant($date);

        return $date instanceof DateTimeInterface ? $this->formatter->date($date, $locale) : '';
    }

    #[AsTwigFilter('site_datetime')]
    public function siteDateTime(DateTimeInterface|string|null $date, ?string $locale = null, bool $proof = false): string
    {
        $date = $this->instant($date);

        return $date instanceof DateTimeInterface ? $this->formatter->dateTime($date, $locale, $proof) : '';
    }

    #[AsTwigFilter('calendar_date')]
    public function calendarDate(DateTimeInterface|string|null $date, ?string $locale = null): string
    {
        $date = $this->instant($date);

        return $date instanceof DateTimeInterface ? $this->formatter->calendarDate($date, $locale) : '';
    }

    /**
     * A string is read the way Twig's own `date` filter reads it, in the
     * process zone (UTC), which is how every stored instant was written. One
     * that does not parse prints nothing rather than failing a mail.
     */
    private function instant(DateTimeInterface|string|null $value): ?DateTimeInterface
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            return '' === $value ? null : new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /** The site's zone, handed to the scripts through `window.__auroraConfig`. */
    #[AsTwigFunction('app_timezone')]
    public function timezone(): string
    {
        return $this->timezone->name();
    }
}
