<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateCategoryEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;

interface ContractTemplateInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getName(): string;

    public function setName(string $name): static;

    public function getKind(): ContractTemplateKindEnum;

    public function setKind(ContractTemplateKindEnum $kind): static;

    public function getCategory(): ?ContractTemplateCategoryEnum;

    public function setCategory(?ContractTemplateCategoryEnum $category): static;

    public function getArchivedAt(): ?DateTimeImmutable;

    public function archive(DateTimeImmutable $at): static;

    public function restore(): static;

    public function isArchived(): bool;

    /** @return Collection<int, ContractTemplateVersionInterface> */
    public function getVersions(): Collection;

    public function addVersion(ContractTemplateVersionInterface $version): static;

    public function removeVersion(ContractTemplateVersionInterface $version): static;

    /** The version being written, if one is open. At most one exists at a time. */
    public function getDraft(): ?ContractTemplateVersionInterface;

    /** The version a contract would be built from today, or null before the first publication. */
    public function getLatestPublishedVersion(): ?ContractTemplateVersionInterface;

    /**
     * Hands out the next version number and burns it.
     *
     * Monotonic and never reused, so a number names one document for the life
     * of the template even after a draft is discarded - which the audit trail
     * needs, since it records numbers rather than ids.
     */
    public function claimNextVersionNumber(): int;

    /** The last number handed out, whether that version still exists or not. */
    public function getVersionCounter(): int;
}
