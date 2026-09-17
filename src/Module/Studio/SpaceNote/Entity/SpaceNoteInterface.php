<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;

interface SpaceNoteInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getSpace(): CustomerSpaceInterface;

    public function setSpace(CustomerSpaceInterface $space): static;

    public function getTitle(): string;

    public function setTitle(string $title): static;

    /** @return list<array<string, mixed>> */
    public function getBody(): array;

    /** @param list<array<string, mixed>> $body */
    public function setBody(array $body): static;

    public function getColourSlot(): ?int;

    public function setColourSlot(?int $colourSlot): static;

    public function isPinned(): bool;

    public function setPinned(bool $pinned): static;

    public function getAuthorLabel(): string;

    public function getAuthor(): ?CoreUserInterface;

    public function takenBy(CoreUserInterface $author, string $label): static;
}
