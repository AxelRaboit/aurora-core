<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Throwable;

/**
 * The site's timezone: the Localisation > Timezone setting.
 *
 * **It applies at the edges, never to storage.** Columns are naive
 * `datetime_immutable`, all written in UTC (the PHP process runs in UTC, in
 * development and in production alike): changing the process timezone would
 * silently reinterpret every instant already stored. So this service does two
 * things only:
 *
 * - **read a typed time** that carries no offset (`2026-10-02T09:00`, what the
 *   date picker sends) as a time of the site, and hand it back in UTC for the
 *   database ({@see self::parseLocal()});
 * - **show** an instant at the site's time ({@see self::toLocal()}).
 *
 * A blank setting, or one naming no known zone, falls back to the setting's
 * default rather than failing a page or a mail.
 */
final readonly class SiteTimezone
{
    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    public function get(): DateTimeZone
    {
        try {
            return new DateTimeZone($this->name());
        } catch (Exception) {
            return new DateTimeZone(ApplicationParameterEnum::Timezone->getDefaultValue());
        }
    }

    public function name(): string
    {
        $default = ApplicationParameterEnum::Timezone->getDefaultValue();

        try {
            $value = $this->settingRepository->getOrDefault(ApplicationParameterEnum::Timezone);
        } catch (Throwable) {
            // No database yet (first boot, the schedule built before the
            // migrations ran): the default beats an outage.
            return $default;
        }

        return in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : $default;
    }

    /**
     * A typed date, read at the site's time, handed back in UTC for storage.
     *
     * A value that already carries its offset (`+02:00`, `Z`) keeps it: only a
     * bare time is tied to the site's timezone.
     */
    public function parseLocal(?string $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $this->get())->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /** The same instant, at the site's time. */
    public function toLocal(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone($this->get());
    }
}
