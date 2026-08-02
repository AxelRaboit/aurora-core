<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Readonly per property rather than a readonly class, so a client can
 * extend this DTO with a mutable field of its own.
 */
class PostTypeInput implements PostTypeInputInterface
{
    /**
     * @param list<string> $supports
     */
    public function __construct(
        #[Assert\NotBlank(message: 'backend.post_types.errors.slug_required')]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'backend.post_types.errors.slug_format')]
        #[Assert\Length(max: 100)]
        public readonly string $slug,
        #[Assert\NotBlank(message: 'backend.post_types.errors.label_required')]
        #[Assert\Length(max: 100)]
        public readonly string $label,
        public readonly ?string $icon = null,
        public readonly bool $hasArchive = false,
        public readonly array $supports = [],
    ) {}

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function hasArchive(): bool
    {
        return $this->hasArchive;
    }

    public function getSupports(): array
    {
        return $this->supports;
    }
}
