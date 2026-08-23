<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\View;

use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Module\Planning\Planning\Serializer\PlanningSerializer;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeZone;

/**
 * What the calendar screen needs before Vue mounts.
 *
 * A view builder because that is what the decision matrix says owns "construction
 * du payload d'une vue (charge, mappe, sérialise)" - and because the controller
 * action had grown four collaborators to assemble one array, which is the shape
 * `convention_thin_controller` exists to prevent.
 */
final readonly class PlanningViewBuilder
{
    public function __construct(
        private PlanningRepository $plannings,
        private PlanningSerializer $planningSerializer,
        private UserRepository $users,
    ) {}

    /** @return array<string, mixed> */
    public function calendarView(CoreUserInterface $reader): array
    {
        return [
            'calendars' => $this->planningSerializer->serializeMany($this->plannings->findVisibleTo($reader)),
            // The zones the runtime can resolve, which is what the DTO validates
            // against. A list of our own would drift from PHP's, and a calendar
            // cutting its days in a zone the runtime cannot resolve puts every
            // all-day event on the wrong day.
            'timezones' => DateTimeZone::listIdentifiers(),
            'people' => $this->invitablePeople(),
            // So the screen can tell which attendee is the reader, and which
            // calendar is theirs to share.
            'currentUserId' => $reader->getId(),
        ];
    }

    /**
     * The accounts that can be invited or shared with.
     *
     * Backend accounts only. A front-office account has no calendar to be invited
     * into, and offering one would be an invitation nobody can answer.
     *
     * @return list<array{value: int, label: string}>
     */
    private function invitablePeople(): array
    {
        $people = [];
        foreach ($this->users->findBy(['type' => UserTypeEnum::Backend->value], ['name' => 'ASC']) as $user) {
            $people[] = ['value' => (int) $user->getId(), 'label' => $user->getName()];
        }

        return $people;
    }
}
