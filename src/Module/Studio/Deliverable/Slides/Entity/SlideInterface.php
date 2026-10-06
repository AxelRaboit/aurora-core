<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Slides\Entity;

use Aurora\Core\Timestampable\TimestampableInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Slides\Enum\SlideLayoutEnum;

interface SlideInterface extends TimestampableInterface
{
    public function getId(): ?int;

    public function getDeliverable(): ?DeliverableInterface;

    public function setDeliverable(?DeliverableInterface $deliverable): static;

    public function getLayout(): SlideLayoutEnum;

    public function setLayout(SlideLayoutEnum $layout): static;

    /** @return array<string, mixed> */
    public function getContent(): array;

    /** @param array<string, mixed> $content */
    public function setContent(array $content): static;

    public function getSpeakerNotes(): ?string;

    public function setSpeakerNotes(?string $speakerNotes): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;
}
