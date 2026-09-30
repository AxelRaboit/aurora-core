<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Signature\Entity;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Signature\Enum\ContractSignatureRoleEnum;
use Aurora\Module\Studio\Contract\Signature\Exception\SignedContractIsImmutableException;
use DateTimeImmutable;

interface ContractSignatureInterface
{
    public function getId(): ?int;

    /** @throws SignedContractIsImmutableException once the row has been recorded */
    public function assertUnrecorded(): void;

    public function getContract(): ContractInterface;

    public function setContract(ContractInterface $contract): static;

    public function getRole(): ContractSignatureRoleEnum;

    public function setRole(ContractSignatureRoleEnum $role): static;

    public function getDeclaredFirstName(): string;

    public function setDeclaredFirstName(string $declaredFirstName): static;

    public function getDeclaredLastName(): string;

    public function setDeclaredLastName(string $declaredLastName): static;

    public function getDeclaredFullName(): string;

    public function getDeclaredEmail(): string;

    public function setDeclaredEmail(string $declaredEmail): static;

    public function getDeclaredPlace(): string;

    public function setDeclaredPlace(string $declaredPlace): static;

    public function getDeclaredDate(): DateTimeImmutable;

    public function setDeclaredDate(DateTimeImmutable $declaredDate): static;

    public function getSignedAt(): DateTimeImmutable;

    public function setSignedAt(DateTimeImmutable $signedAt): static;

    public function getIpAddress(): ?string;

    public function setIpAddress(?string $ipAddress): static;

    public function getUserAgent(): ?string;

    public function setUserAgent(?string $userAgent): static;

    public function getLinkSelector(): ?string;

    public function setLinkSelector(?string $linkSelector): static;

    public function getChallengeSentTo(): ?string;

    public function setChallengeSentTo(?string $challengeSentTo): static;

    public function getChallengeVerifiedAt(): ?DateTimeImmutable;

    public function setChallengeVerifiedAt(?DateTimeImmutable $challengeVerifiedAt): static;

    public function getUser(): ?CoreUserInterface;

    public function setUser(?CoreUserInterface $user): static;

    public function getSignedContentHash(): string;

    public function setSignedContentHash(string $signedContentHash): static;

    public function getSignatureImage(): ?string;

    public function setSignatureImage(?string $signatureImage): static;

    public function getCreatedAt(): DateTimeImmutable;

    /** Whether this signature still covers the document it is attached to. */
    public function coversCurrentDocument(): bool;
}
