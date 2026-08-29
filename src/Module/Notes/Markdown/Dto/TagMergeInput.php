<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class TagMergeInput
{
    /**
     * @param list<string> $sourceTags
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'notes.markdown.tags.errors.sources_empty')]
        #[Assert\All([
            new Assert\NotBlank(message: 'notes.markdown.tags.errors.empty'),
            new Assert\Length(max: 64),
        ])]
        public array $sourceTags,
        #[Assert\NotBlank(message: 'notes.markdown.tags.errors.empty')]
        #[Assert\Length(max: 64)]
        public string $targetTag,
    ) {}
}
