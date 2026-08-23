<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Attendee\Entity;

use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody invited to an event, and whether they are coming.
 *
 * An entity rather than a plain many-to-many, because the interesting part is the
 * answer and a join table cannot hold one. A list of attendees without a status
 * says who was asked, not who will be there - and only the second is something an
 * organiser acts on.
 *
 * A surrogate id rather than the composite key the orphaned table had. Doctrine
 * handles composites, but every route that ever wants to name one attendee would
 * have to carry two values, and `(event, user)` stays unique either way.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPlanningEventAttendee implements PlanningEventAttendeeInterface
{
    #[ORM\ManyToOne(targetEntity: PlanningEventInterface::class, inversedBy: 'attendees')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PlanningEventInterface $event;

    /**
     * Cascaded on delete: an invitation to an account that no longer exists is a
     * row nothing can render and nobody can answer.
     */
    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    #[ORM\Column(length: 20, enumType: PlanningAttendeeStatusEnum::class, options: ['default' => PlanningAttendeeStatusEnum::NeedsAction->value])]
    protected PlanningAttendeeStatusEnum $status = PlanningAttendeeStatusEnum::NeedsAction;

    /**
     * When they answered, or null while they have not.
     *
     * A timestamp rather than nothing, for the reason the alerts have one: "when
     * did they accept" is the question asked the day two people remember the
     * invitation differently.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $respondedAt = null;

    abstract public function getId(): ?int;

    public function getEvent(): PlanningEventInterface
    {
        return $this->event;
    }

    public function setEvent(PlanningEventInterface $event): static
    {
        $this->event = $event;

        return $this;
    }

    public function getUser(): CoreUserInterface
    {
        return $this->user;
    }

    public function setUser(CoreUserInterface $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getStatus(): PlanningAttendeeStatusEnum
    {
        return $this->status;
    }

    /**
     * Records an answer.
     *
     * Going back to `NeedsAction` clears the timestamp, because that is not an
     * answer - it is the absence of one, and a date beside it would say somebody
     * decided not to decide at a particular moment.
     */
    public function respond(PlanningAttendeeStatusEnum $status, DateTimeImmutable $at): static
    {
        $this->status = $status;
        $this->respondedAt = PlanningAttendeeStatusEnum::NeedsAction === $status ? null : $at;

        return $this;
    }

    public function getRespondedAt(): ?DateTimeImmutable
    {
        return $this->respondedAt;
    }
}
