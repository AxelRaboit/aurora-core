<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Serializer;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A calendar, as the sidebar draws it.
 *
 * The colour travels as its slot and not as a value: the screen turns it into
 * `var(--chart-cat-N)`, which is what lets the same calendar be legible in both
 * themes. Sending a resolved colour from here would freeze it to whichever theme
 * the request happened to be in.
 *
 * No event count. It used to send one, and it was wrong twice over: a lifetime
 * total computed here is a COUNT query per calendar on every page load, and it
 * is a snapshot - creating an event does not reload the calendar list, so a
 * calendar you had just filled kept saying nothing was in it. The screen counts
 * the events it already holds instead.
 */
final readonly class PlanningSerializer
{
    public function __construct(private TranslatorInterface $translator) {}

    /** @return array<string, mixed> */
    public function serialize(PlanningInterface $planning): array
    {
        return [
            'id' => $planning->getId(),
            'name' => $planning->getName(),
            'description' => $planning->getDescription(),
            'colourSlot' => $planning->getColourSlot(),
            'timezone' => $planning->getTimezone(),
            'visibility' => $planning->getVisibility()->value,
            'visibilityLabel' => $this->translator->trans($planning->getVisibility()->getLabelKey()),
            'ownerName' => $planning->getOwner()?->getName(),
            // Whether a feed is published, not the token. The screen needs to know
            // there is one so it can offer to revoke it; the address itself comes
            // back only from the request that created it, which is the one moment
            // somebody asked to see it.
            'hasFeed' => $planning->hasFeed(),
            'shares' => $this->shares($planning),
            // The id, not a boolean: this serializer has no idea who is asking, and
            // an `isOwner` computed here would have said "has an owner" - which is
            // true of every calendar somebody made.
            'ownerId' => $planning->getOwner()?->getId(),
        ];
    }

    /**
     * Who this calendar is shared with, by name.
     *
     * @return list<array<string, mixed>>
     */
    private function shares(PlanningInterface $planning): array
    {
        $rows = [];
        foreach ($planning->getShares() as $share) {
            $rows[] = [
                'userId' => $share->getUser()->getId(),
                'name' => $share->getUser()->getName(),
                'canWrite' => $share->canWrite(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * @param iterable<PlanningInterface> $plannings
     *
     * @return list<array<string, mixed>>
     */
    public function serializeMany(iterable $plannings): array
    {
        $out = [];
        foreach ($plannings as $planning) {
            $out[] = $this->serialize($planning);
        }

        return $out;
    }
}
