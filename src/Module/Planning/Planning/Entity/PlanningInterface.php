<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Planning\Entity;

use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\Common\Collections\Collection;

/**
 * A calendar: a named set of events with one colour.
 *
 * The interface rather than the class is what everything else names, so a client
 * project can add a field to its own subclass without forking anything - see
 * `docs/aurora-core/dev/entity_extensibility_convention.md`.
 */
interface PlanningInterface
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

    public function getVisibility(): PlanningVisibilityEnum;

    public function setVisibility(PlanningVisibilityEnum $visibility): static;

    public function getOwner(): ?CoreUserInterface;

    public function setOwner(?CoreUserInterface $owner): static;

    /**
     * @return Collection<int, PlanningEventInterface>
     */
    public function getEvents(): Collection;
}
