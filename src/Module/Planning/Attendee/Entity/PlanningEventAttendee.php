<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Attendee\Entity;

use Aurora\Module\Planning\Attendee\Repository\PlanningEventAttendeeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlanningEventAttendeeRepository::class)]
#[ORM\Table(name: 'core_planning_event_attendees')]
// One invitation per person per event. Two rows for the same pair is a state with
// no correct rendering: the grid would have to choose which answer to believe.
#[ORM\UniqueConstraint(name: 'uniq_planning_attendee', columns: ['event_id', 'user_id'])]
// "What am I invited to" - asked by every screen that shows somebody their own
// invitations, and by the notification that tells them about a new one.
#[ORM\Index(name: 'idx_planning_attendee_user', columns: ['user_id', 'status'])]
class PlanningEventAttendee extends AbstractPlanningEventAttendee
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'SEQUENCE')]
    #[ORM\SequenceGenerator(sequenceName: 'seq_core_planning_event_attendee_id', allocationSize: 1)]
    #[ORM\Column]
    protected ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
