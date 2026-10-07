<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class SpaceContentItemInput implements SpaceContentItemInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.space_content.errors.title_required')]
        #[Assert\Length(max: 255, maxMessage: 'suite.studio.space_content.errors.title_too_long')]
        public readonly string $title = '',
        #[Assert\Length(max: 20000)]
        public readonly ?string $body = null,
        // Checked as "present" rather than "belongs to this space": the Manager
        // holds the space and is the only layer that can answer the second.
        #[Assert\NotNull(message: 'suite.studio.space_content.errors.column_required')]
        #[Assert\Positive(message: 'suite.studio.space_content.errors.column_required')]
        public readonly ?int $columnId = null,
        // The shape an `<input type="datetime-local">` sends. Seconds are
        // accepted because some browsers add them.
        #[Assert\Regex(
            pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/',
            message: 'suite.studio.space_content.errors.scheduled_at_invalid',
        )]
        public readonly ?string $scheduledAt = null,
        #[Assert\Regex(
            pattern: '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}(:\\d{2})?$/',
            message: 'suite.studio.space_content.errors.review_by_invalid',
        )]
        public readonly ?string $reviewBy = null,
        // True by default: an old form, or a call that does not know this
        // field, keeps the previous behaviour rather than making the card
        // disappear from the calendar without anybody asking for it.
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

    public function getReviewBy(): ?string
    {
        return $this->reviewBy;
    }

    public function isShownOnCalendar(): bool
    {
        return $this->showOnCalendar;
    }
}
