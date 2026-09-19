<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class SpaceContentItemInput implements SpaceContentItemInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.space_content.errors.title_required')]
        #[Assert\Length(max: 255, maxMessage: 'backend.studio.space_content.errors.title_too_long')]
        public readonly string $title = '',
        #[Assert\Length(max: 20000)]
        public readonly ?string $body = null,
        // Checked as "present" rather than "belongs to this space": the Manager
        // holds the space and is the only layer that can answer the second.
        #[Assert\NotNull(message: 'backend.studio.space_content.errors.column_required')]
        #[Assert\Positive(message: 'backend.studio.space_content.errors.column_required')]
        public readonly ?int $columnId = null,
        // The shape an `<input type="datetime-local">` sends. Seconds are
        // accepted because some browsers add them.
        #[Assert\Regex(
            pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/',
            message: 'backend.studio.space_content.errors.scheduled_at_invalid',
        )]
        public readonly ?string $scheduledAt = null,
        // Vrai par défaut : un formulaire ancien, ou un appel qui ne connaît
        // pas ce champ, garde le comportement d'avant plutôt que de faire
        // disparaître la carte du calendrier sans que personne l'ait demandé.
        public readonly bool $showOnCalendar = true,
    ) {}

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getColumnId(): ?int
    {
        return $this->columnId;
    }

    public function getScheduledAt(): ?string
    {
        return $this->scheduledAt;
    }

    public function isShownOnCalendar(): bool
    {
        return $this->showOnCalendar;
    }
}
