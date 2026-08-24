<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Entity;

use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface PlanningShareLinkInterface
{
    public function getId(): ?int;

    public function getToken(): string;

    public function getLabel(): string;

    public function setLabel(string $label): static;

    /** @return Collection<int, PlanningInterface> */
    public function getCalendars(): Collection;

    public function addCalendar(PlanningInterface $planning): static;

    public function removeCalendar(PlanningInterface $planning): static;

    public function getMode(): PlanningShareLinkModeEnum;

    public function setMode(PlanningShareLinkModeEnum $mode): static;

    public function getExpiresAt(): ?DateTimeImmutable;

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static;

    public function getRevokedAt(): ?DateTimeImmutable;

    public function revoke(DateTimeImmutable $at): static;

    public function getLastUsedAt(): ?DateTimeImmutable;

    public function markUsed(DateTimeImmutable $at): static;

    public function getCreatedAt(): DateTimeImmutable;

    /** Whether this address still works at that moment: not revoked, not expired. */
    public function isUsableAt(DateTimeImmutable $now): bool;
}
