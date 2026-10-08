<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Entity;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface MarkdownNoteMemberInterface
{
    public function getId(): ?int;

    public function getNote(): MarkdownNoteInterface;

    public function setNote(MarkdownNoteInterface $note): static;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getRole(): NoteMemberRoleEnum;

    public function setRole(NoteMemberRoleEnum $role): static;

    public function getCreatedAt(): DateTimeImmutable;
}
