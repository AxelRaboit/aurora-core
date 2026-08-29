<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Preview\Entity;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

use function bin2hex;
use function random_bytes;

/**
 * A short-lived address that shows one publication before it is published.
 *
 * The front serves `Published` and nothing else, which is right - and left nobody
 * able to look at a draft. Not the author, who published to find out how it
 * rendered; not a reviewer, who was asked to approve something they could not
 * read; not a client, who was sent a screenshot.
 *
 * **Deliberately smaller than `PlanningShareLink`**, which does the same trick for
 * calendars. That one is a thing somebody manages: it has a label, points at
 * several calendars, and lives until revoked, because handing a schedule to an
 * outsider is a decision with a lifetime. A preview is a glance - one post, one
 * short window, minted by clicking a button and forgotten. Giving it a management
 * screen would be furniture nobody asked for.
 *
 * The duplication between the two is real and deliberate for now. A third of these
 * is the point at which the token, the expiry and the "unknown, expired and
 * revoked all answer alike" rule belong in `Aurora\Core` rather than copied again.
 */
#[ORM\MappedSuperclass]
abstract class AbstractPostPreviewToken implements PostPreviewTokenInterface
{
    /**
     * How long a freshly minted preview lasts.
     *
     * Long enough to send to somebody and have them look at it after the weekend,
     * short enough that a link pasted into a chat does not still open the draft
     * next year.
     */
    public const int LIFETIME_DAYS = 7;

    /** 32 bytes, hex. The same generator every unguessable URL here uses. */
    #[ORM\Column(length: 64, unique: true)]
    protected string $token;

    #[ORM\ManyToOne(targetEntity: PostInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PostInterface $post;

    /**
     * Who minted it.
     *
     * Not for permissions - the token is the permission - but because a draft that
     * leaked was leaked by somebody, and a row with no author cannot say who.
     */
    #[ORM\ManyToOne(targetEntity: CoreUserInterface::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CoreUserInterface $createdBy = null;

    #[ORM\Column]
    protected DateTimeImmutable $expiresAt;

    #[ORM\Column]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify(sprintf('+%d days', self::LIFETIME_DAYS));
    }

    abstract public function getId(): ?int;

    public function getToken(): string
    {
        return $this->token;
    }

    public function getPost(): PostInterface
    {
        return $this->post;
    }

    public function setPost(PostInterface $post): static
    {
        $this->post = $post;

        return $this;
    }

    public function getCreatedBy(): ?CoreUserInterface
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?CoreUserInterface $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Inclusive of the instant itself: a preview good "until 18:00" stops at 18:00
     * rather than lasting the rest of that second.
     */
    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt > $now;
    }
}
