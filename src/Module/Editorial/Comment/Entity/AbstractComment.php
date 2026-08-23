<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Entity;

use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reader's comment on a post.
 *
 * Only `createdAt` is kept, and it is set once in the constructor rather than
 * through TimestampableTrait. A comment is not edited - it is approved,
 * marked as spam or deleted - and when each of those happened is already in
 * the audit log, with who did it. A second column saying "something changed"
 * would answer less than what is already recorded.
 */
#[ORM\MappedSuperclass]
abstract class AbstractComment implements CommentInterface
{
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    protected ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: PostInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected PostInterface $post;

    #[ORM\Column(length: 100)]
    protected string $authorName;

    /**
     * Never shown to a reader. It is here so a moderator can recognise a
     * repeat offender and so the author can be told their comment went live.
     */
    #[ORM\Column(length: 180)]
    protected string $authorEmail;

    #[ORM\Column(type: Types::TEXT)]
    protected string $content;

    #[ORM\Column(length: 50, enumType: CommentStatusEnum::class)]
    protected CommentStatusEnum $status = CommentStatusEnum::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    /**
     * `SET NULL`, so deleting a comment promotes its replies instead of
     * silently taking a whole conversation with it - the same rule the menu
     * tree follows.
     */
    #[ORM\ManyToOne(targetEntity: CommentInterface::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CommentInterface $parent = null;

    /** @var Collection<int, CommentInterface> */
    #[ORM\OneToMany(targetEntity: CommentInterface::class, mappedBy: 'parent')]
    protected Collection $replies;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->replies = new ArrayCollection();
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
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

    public function getAuthorName(): string
    {
        return $this->authorName;
    }

    public function setAuthorName(string $authorName): static
    {
        $this->authorName = $authorName;

        return $this;
    }

    public function getAuthorEmail(): string
    {
        return $this->authorEmail;
    }

    public function setAuthorEmail(string $authorEmail): static
    {
        $this->authorEmail = $authorEmail;

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getStatus(): CommentStatusEnum
    {
        return $this->status;
    }

    public function setStatus(CommentStatusEnum $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isApproved(): bool
    {
        return $this->status->isPublic();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getParent(): ?CommentInterface
    {
        return $this->parent;
    }

    public function setParent(?CommentInterface $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    public function getReplies(): Collection
    {
        return $this->replies;
    }
}
