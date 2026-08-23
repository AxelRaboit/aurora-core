<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Reminder\Dto;

use Aurora\Core\Support\Str;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PlanningReminderInputFactoryInterface::class)]
class PlanningReminderInputFactory implements PlanningReminderInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningReminderInputInterface
    {
        return new PlanningReminderInput(
            planningId: is_numeric($data['planningId'] ?? null) ? (int) $data['planningId'] : 0,
            title: Str::trimFromArray($data, 'title'),
            notes: Str::trimOrNullFromArray($data, 'notes'),
            dueAt: $this->date($data['dueAt'] ?? null),
            allDay: (bool) ($data['allDay'] ?? false),
            completed: (bool) ($data['completed'] ?? false),
        );
    }

    /**
     * An instant, in UTC, or null for anything unparseable.
     *
     * Same rule as events, and it has to be the same rule: the column has no
     * timezone, so every instant in the database is UTC and the only place a wall
     * clock exists is a screen. A reminder read as UTC when the reader meant
     * Paris arrives two hours late.
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
