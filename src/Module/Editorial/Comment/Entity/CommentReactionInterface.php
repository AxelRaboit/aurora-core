<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Entity;

use Aurora\Module\Editorial\Comment\Enum\ReactionTypeEnum;

interface CommentReactionInterface
{
    public function getId(): ?int;

    public function getComment(): CommentInterface;

    public function setComment(CommentInterface $comment): static;

    public function getType(): ReactionTypeEnum;

    public function setType(ReactionTypeEnum $type): static;

    public function getFingerprint(): string;

    public function setFingerprint(string $fingerprint): static;
}
