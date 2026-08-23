<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminderInterface;
use Aurora\Module\Planning\Share\Entity\PlanningShareInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Collection;

/**
 * A calendar: a named set of events with one colour.
 *
 * The interface rather than the class is what everything else names, so a client
 * project can add a field to its own subclass without forking anything - see
 * `docs/aurora-core/dev/entity_extensibility_convention.md`.
 */
interface PlanningInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    public function getColourSlot(): int;

    public function setColourSlot(int $colourSlot): static;

    public function getTimezone(): string;

    public function setTimezone(string $timezone): static;

    public function getSourceType(): ?string;

    public function setSourceType(?string $sourceType): static;

    public function isFromModule(): bool;

    public function getFeedToken(): ?string;

    public function hasFeed(): bool;

    public function publishFeed(): static;

    public function revokeFeed(): static;

    /**
     * @return Collection<int, PlanningReminderInterface>
     */
    public function getReminders(): Collection;

    /**
     * @return Collection<int, PlanningShareInterface>
     */
    public function getShares(): Collection;

    public function addShare(PlanningShareInterface $share): static;

    public function removeShare(PlanningShareInterface $share): static;

    public function isWritableBy(CoreUserInterface $user): bool;

    public function getVisibility(): PlanningVisibilityEnum;

    public function setVisibility(PlanningVisibilityEnum $visibility): static;

    public function getOwner(): ?CoreUserInterface;

    public function setOwner(?CoreUserInterface $owner): static;

    /**
     * @return Collection<int, PlanningEventInterface>
     */
    public function getEvents(): Collection;
}
