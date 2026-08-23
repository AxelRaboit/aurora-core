<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Event\Dto;

use Aurora\Core\Support\Str;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use DateTimeImmutable;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PlanningEventInputFactoryInterface::class)]
class PlanningEventInputFactory implements PlanningEventInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningEventInputInterface
    {
        $allDay = (bool) ($data['allDay'] ?? false);
        $startAt = $this->date($data['startAt'] ?? null);
        $endAt = $this->date($data['endAt'] ?? null);

        // An all-day event owns whole days, so its ends are snapped here rather
        // than left to whatever time the client happened to send. Without this a
        // day-long event created at 14:00 spans 14:00 to 14:00 the next day, and
        // a month grid draws it across two cells.
        if ($allDay && $startAt instanceof DateTimeImmutable) {
            $startAt = $startAt->setTime(0, 0);
            $endAt = ($endAt ?? $startAt)->setTime(23, 59, 59);
        }

        return new PlanningEventInput(
            planningId: is_numeric($data['planningId'] ?? null) ? (int) $data['planningId'] : 0,
            title: Str::trimFromArray($data, 'title'),
            description: Str::trimOrNullFromArray($data, 'description'),
            location: Str::trimOrNullFromArray($data, 'location'),
            startAt: $startAt,
            endAt: $endAt,
            allDay: $allDay,
            status: PlanningEventStatusEnum::tryFrom((string) ($data['status'] ?? ''))
                ?? PlanningEventStatusEnum::Confirmed,
        );
    }

    /**
     * Null rather than today for anything unparseable.
     *
     * A date the client sent wrong has to reach validation as absent, so the
     * form says which field is missing. Defaulting here would put the event on
     * today and report success.
     */
    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === mb_trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
