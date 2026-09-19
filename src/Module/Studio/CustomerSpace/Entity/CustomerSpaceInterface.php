<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Entity;

use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolderInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumnInterface;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
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

    public function getDriveFolderId(): ?string;

    public function setDriveFolderId(?string $driveFolderId): static;

    public function setTimezone(string $timezone): static;

    /** @return Collection<int, CustomerSpaceMemberInterface> */
    public function getMembers(): Collection;

    public function addMember(CustomerSpaceMemberInterface $member): static;

    public function removeMember(CustomerSpaceMemberInterface $member): static;

    /** @return Collection<int, SpaceContentColumnInterface> */
    public function getContentColumns(): Collection;

    /** @return Collection<int, SpaceContentItemInterface> */
    public function getContentItems(): Collection;

    public function getCreatedAt(): DateTimeImmutable;

    public function getUpdatedAt(): ?DateTimeImmutable;

    public function getDocumentFolder(): ?DocumentFolderInterface;

    public function setDocumentFolder(?DocumentFolderInterface $documentFolder): static;
}
