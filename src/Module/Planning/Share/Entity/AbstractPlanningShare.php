<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Share\Entity;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person a calendar is shared with.
 *
 * The middle ground the visibility column cannot express. `Private` is nobody and
 * `Shared` is everybody who can reach the module; neither says "these three
 * people", which is what somebody actually wants when they share a calendar.
 *
 * Added beside the visibility rather than replacing it. Turning `Shared` into a
 * list of every account would be the same thing said longer, and it would change
 * who can write to calendars that are shared today - a behaviour nobody asked to
 * change.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPlanningShare implements PlanningShareInterface
{
    #[ORM\ManyToOne(targetEntity: PlanningInterface::class, inversedBy: 'shares')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PlanningInterface $planning;

    /**
     * Cascaded on delete: a share with an account that no longer exists grants
     * nothing to nobody, and leaving the row would make the sharing list show a
     * name that cannot be resolved.
     */
    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CoreUserInterface $user;

    /**
     * Whether they may add and change things, or only look.
     *
     * Two levels and not three. Read and write are the two a reader can hold in
     * their head about somebody else's calendar; a third - "may share it on" -
     * is a question nobody has asked here, and an option nobody uses is an option
     * everybody has to read past.
     */
    #[ORM\Column(options: ['default' => false])]
    protected bool $canWrite = false;

    abstract public function getId(): ?int;

    public function getPlanning(): PlanningInterface
    {
        return $this->planning;
    }

    public function setPlanning(PlanningInterface $planning): static
    {
        $this->planning = $planning;

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

    public function canWrite(): bool
    {
        return $this->canWrite;
    }

    public function setCanWrite(bool $canWrite): static
    {
        $this->canWrite = $canWrite;

        return $this;
    }
}
