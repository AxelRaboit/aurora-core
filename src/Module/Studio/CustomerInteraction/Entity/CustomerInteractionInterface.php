<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerInteraction\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Module\Studio\CustomerInteraction\Enum\CustomerInteractionKindEnum;
use DateTimeImmutable;

interface CustomerInteractionInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getCustomer(): CustomerInterface;

    public function setCustomer(CustomerInterface $customer): static;

    public function getKind(): CustomerInteractionKindEnum;

    public function setKind(CustomerInteractionKindEnum $kind): static;

    public function getOccurredAt(): DateTimeImmutable;

    public function setOccurredAt(DateTimeImmutable $occurredAt): static;

    public function getSummary(): string;

    public function setSummary(string $summary): static;

    public function getAuthorUser(): ?CoreUserInterface;

    public function getAuthorLabel(): string;

    public function setAuthor(?CoreUserInterface $authorUser, string $authorLabel): static;
}
