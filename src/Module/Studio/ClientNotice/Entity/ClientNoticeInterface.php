<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Entity;

use Aurora\Module\Studio\ClientNotice\Enum\ClientNoticeTypeEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;

interface ClientNoticeInterface
{
    public function getId(): ?int;

    public function getLink(): SpaceAccessLinkInterface;

    public function setLink(SpaceAccessLinkInterface $link): static;

    public function getType(): ClientNoticeTypeEnum;

    public function setType(ClientNoticeTypeEnum $type): static;

    /** What the line names: a content's title, a file's name. */
    public function getSubject(): ?string;

    public function setSubject(?string $subject): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function getEmailedAt(): ?DateTimeImmutable;

    public function markEmailed(DateTimeImmutable $at): static;

    public function getSeenAt(): ?DateTimeImmutable;

    public function markSeen(DateTimeImmutable $at): static;
}
