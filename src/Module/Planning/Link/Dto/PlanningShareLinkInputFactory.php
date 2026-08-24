<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Dto;

use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Time\PlanningClock;

use function is_array;
use function is_numeric;
use function is_string;
use function mb_substr;
use function mb_trim;

/**
 * Reads a share-link request out of a payload.
 *
 * The expiry goes through `PlanningClock::utc()` like every other instant in this
 * module, which is not a formality: a naked wall clock read in the process
 * timezone would close a link two hours early or late, and the person who set it
 * would have no way to tell which.
 */
final readonly class PlanningShareLinkInputFactory implements PlanningShareLinkInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningShareLinkInput
    {
        $raw = $data['calendarIds'] ?? [];
        $ids = [];

        if (is_array($raw)) {
            foreach ($raw as $id) {
                if (is_numeric($id)) {
                    $ids[(int) $id] = true;
                }
            }
        }

        $label = $data['label'] ?? '';

        return new PlanningShareLinkInput(
            // Keyed then re-listed, so the same calendar sent twice is one calendar
            // rather than a duplicate row the join table would refuse.
            calendarIds: array_map(intval(...), array_keys($ids)),
            // Trimmed and capped rather than refused on length alone: a label is a
            // note to self, and losing a whole form to a long one is worse than
            // shortening it. The constraint still catches an empty one.
            label: mb_substr(mb_trim(is_string($label) ? $label : ''), 0, 120),
            mode: PlanningShareLinkModeEnum::tryFrom(
                is_string($data['mode'] ?? null) ? $data['mode'] : '',
            ) ?? PlanningShareLinkModeEnum::Web,
            expiresAt: PlanningClock::utc($data['expiresAt'] ?? null),
        );
    }
}
