<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Entity;

use Aurora\Module\Studio\ClientNotice\Enum\ClientNoticeTypeEnum;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One piece of news for one person holding a link to a client space.
 *
 * **The client's half of the notifications.** The studio has its bell; a
 * client has no account, so there is nowhere to ring. What the studio does
 * for them - a message, a file shared, a content to review - is written here
 * instead, against the link it is for, and read twice: by the digest mail,
 * and by « Depuis votre dernière visite » at the top of their page.
 *
 * **Per link, not per space.** Two people on one space hold two links with
 * different rights: the one who may not approve is not told a content
 * awaits their opinion. Rights are applied when the notice is written.
 *
 * **Two dates close it.** `seenAt` when the client opened their page after
 * it, which is what the page shows and what keeps the mail from repeating
 * what they have already read; `emailedAt` when a digest carried it.
 *
 * Only the subject is stored, never a sentence: the line is written in the
 * customer's language when it is read, and a sentence frozen in the language
 * of the person who caused it would be the bug the studio's bell had.
 */
#[ORM\MappedSuperclass]
abstract class AbstractClientNotice implements ClientNoticeInterface
{
    #[ORM\ManyToOne(targetEntity: SpaceAccessLinkInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected SpaceAccessLinkInterface $link;

    #[ORM\Column(length: 30, enumType: ClientNoticeTypeEnum::class)]
    protected ClientNoticeTypeEnum $type;

    #[ORM\Column(length: 255, nullable: true)]
    protected ?string $subject = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $emailedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $seenAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    abstract public function getId(): ?int;

    public function getLink(): SpaceAccessLinkInterface
    {
        return $this->link;
    }

    public function setLink(SpaceAccessLinkInterface $link): static
    {
        $this->link = $link;

        return $this;
    }

    public function getType(): ClientNoticeTypeEnum
    {
        return $this->type;
    }

    public function setType(ClientNoticeTypeEnum $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        // Cut rather than refused: a title is the studio's to write, and a
        // notice that cannot be stored is news that is lost.
        $this->subject = null === $subject ? null : mb_substr($subject, 0, 255);

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEmailedAt(): ?DateTimeImmutable
    {
        return $this->emailedAt;
    }

    public function markEmailed(DateTimeImmutable $at): static
    {
        $this->emailedAt = $at;

        return $this;
    }

    public function getSeenAt(): ?DateTimeImmutable
    {
        return $this->seenAt;
    }

    public function markSeen(DateTimeImmutable $at): static
    {
        $this->seenAt ??= $at;

        return $this;
    }
}
