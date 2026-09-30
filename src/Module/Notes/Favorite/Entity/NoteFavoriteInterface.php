<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Favorite\Entity;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;

interface NoteFavoriteInterface
{
    public function getId(): ?int;

    public function getUser(): CoreUserInterface;

    public function setUser(CoreUserInterface $user): static;

    public function getNote(): ?MarkdownNoteInterface;

    public function setNote(?MarkdownNoteInterface $note): static;

    public function getFolder(): ?NoteFolderInterface;

    public function setFolder(?NoteFolderInterface $folder): static;

    public function getCreatedAt(): DateTimeImmutable;

    public function setCreatedAt(DateTimeImmutable $at): static;
}
