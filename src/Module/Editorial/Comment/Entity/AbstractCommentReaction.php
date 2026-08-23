<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Entity;

use Aurora\Module\Editorial\Comment\Enum\ReactionTypeEnum;
use Doctrine\ORM\Mapping as ORM;

/**
 * One reader's reaction to one comment.
 *
 * Uniqueness is on (comment, fingerprint) - deliberately not including the
 * type. A reader holds one reaction per comment, so picking a different emoji
 * replaces the previous one rather than stacking; picking the same one again
 * withdraws it.
 *
 * The fingerprint stands in for a reader who is not signed in. It is a hash,
 * never an address: comments are public, and a table mapping opinions to IP
 * addresses is a liability with no feature behind it.
 */
#[ORM\MappedSuperclass]
abstract class AbstractCommentReaction implements CommentReactionInterface
{
    #[ORM\ManyToOne(targetEntity: CommentInterface::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected CommentInterface $comment;

    #[ORM\Column(length: 20, enumType: ReactionTypeEnum::class)]
    protected ReactionTypeEnum $type;

    #[ORM\Column(length: 64)]
    protected string $fingerprint;

    public function getComment(): CommentInterface
    {
        return $this->comment;
    }

    public function setComment(CommentInterface $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getType(): ReactionTypeEnum
    {
        return $this->type;
    }

    public function setType(ReactionTypeEnum $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): static
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }
}
