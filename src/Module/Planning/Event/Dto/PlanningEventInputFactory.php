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
            alertOffsets: $this->alertOffsets($data['alerts'] ?? null),
        );
    }

    /**
     * The offsets the form sent, kept only if the entity would accept them.
     *
     * Dropped rather than reported, unlike a bad date. An offset outside the
     * list is not something the form can produce - there is no free-text field
     * for it - so it means a hand-written request, and the honest answer to
     * "remind me 7 minutes before" from a picker that only offers nine values is
     * to ignore it rather than to fail the save of a legitimate event.
     *
     * Deduplicated because the table is unique on (event, offset), and sorted so
     * the payload reads in the order the form draws it.
     *
     * @return list<int>
     */
    private function alertOffsets(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $offsets = [];
        foreach ($value as $offset) {
            if (is_numeric($offset) && in_array((int) $offset, PlanningEventAlert::OFFSETS, true)) {
                $offsets[] = (int) $offset;
            }
        }

        $offsets = array_values(array_unique($offsets));
        sort($offsets);

        return $offsets;
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
