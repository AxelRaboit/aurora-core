<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Entity;

use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface CustomerSpaceInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): static;

    public function getDescription(): ?string;

    public function setDescription(?string $description): static;

    public function getCustomer(): CustomerInterface;

    public function setCustomer(CustomerInterface $customer): static;

    public function getStatus(): CustomerSpaceStatusEnum;

    public function setStatus(CustomerSpaceStatusEnum $status): static;

    public function isArchived(): bool;

    public function getColourSlot(): int;

    public function setColourSlot(int $colourSlot): static;

    public function getTimezone(): string;

    public function setTimezone(string $timezone): static;

    /** @return Collection<int, CustomerSpaceMemberInterface> */
    public function getMembers(): Collection;

    public function addMember(CustomerSpaceMemberInterface $member): static;

    public function removeMember(CustomerSpaceMemberInterface $member): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function getUpdatedAt(): ?DateTimeImmutable;
}
