<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PlanningEventInputFactoryInterface::class)]
class PlanningEventInputFactory implements PlanningEventInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningEventInputInterface
    {
        return new PlanningEventInput(
            planningId: is_numeric($data['planningId'] ?? null) ? (int) $data['planningId'] : 0,
            title: Str::trimFromArray($data, 'title'),
            description: Str::trimOrNullFromArray($data, 'description'),
            location: Str::trimOrNullFromArray($data, 'location'),
            startAt: $this->date($data['startAt'] ?? null),
            endAt: $this->date($data['endAt'] ?? null),
            allDay: (bool) ($data['allDay'] ?? false),
            status: PlanningEventStatusEnum::tryFrom((string) ($data['status'] ?? ''))
                ?? PlanningEventStatusEnum::Confirmed,
            // Absent and empty both mean "follow the calendar". An empty select
            // sends "", and reading that as slot zero would draw nothing.
            colourSlot: is_numeric($data['colourSlot'] ?? null) ? (int) $data['colourSlot'] : null,
            alerts: $this->alerts($data['alerts'] ?? null),
        );
    }

    /**
     * The alerts the form sent, each kept only if it means something.
     *
     * Two shapes in one list: `{minutes: 30}` for an offset the menu offers, and
     * `{at: "..."}` for a moment somebody picked. Anything else is dropped rather
     * than reported - an offset outside the menu is not something the select can
     * produce, so it means a hand-written request, and failing the save of an
     * otherwise valid event over it would be the wrong trade.
     *
     * Deduplicated on the offset, because two "30 minutes before" is a double
     * notification and the kind of duplicate a form produces by being submitted
     * twice rather than by anyone meaning it. Pinned moments are left alone here
     * and constrained by the table instead, which is where their identity lives.
     *
     * @return list<array{minutes: int|null, at: DateTimeImmutable|null}>
     */
    private function alerts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $alerts = [];
        $offsetsSeen = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $minutes = $row['minutes'] ?? null;
            if (is_numeric($minutes) && in_array((int) $minutes, PlanningEventAlert::OFFSETS, true)) {
                if (in_array((int) $minutes, $offsetsSeen, true)) {
                    continue;
                }

                $offsetsSeen[] = (int) $minutes;
                $alerts[] = ['minutes' => (int) $minutes, 'at' => null];

                continue;
            }

            $at = $this->date($row['at'] ?? null);
            if ($at instanceof DateTimeImmutable) {
                $alerts[] = ['minutes' => null, 'at' => $at];
            }
        }

        return $alerts;
    }

    /**
     * An instant, in UTC, or null for anything unparseable.
     *
     * Null rather than today: a date the client sent wrong has to reach
     * validation as absent, so the form says which field is missing. Defaulting
     * here would put the event on today and report success.
     *
     * Normalised to UTC because the client sends an offset and the column has no
     * timezone. Without the conversion, whatever offset the reader's browser
     * happened to have would be dropped on write and read back as UTC - so an
     * event typed at 10:00 in Paris came back at 12:00. Every instant in the
     * table is UTC, and the only place a wall clock exists is a screen.
     */
    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value)->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }
}
