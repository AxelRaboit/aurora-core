<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\PostType\Dto;

use Aurora\Module\Editorial\PostType\Entity\AbstractPostTypeField;
use Symfony\Component\Validator\Constraints as Assert;

class PostTypeFieldInput implements PostTypeFieldInputInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        #[Assert\NotBlank(message: 'backend.post_types.errors.field_name_required')]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'backend.post_types.errors.field_name_format')]
        #[Assert\Length(max: 100)]
        public readonly string $name,
        #[Assert\NotBlank(message: 'backend.post_types.errors.field_label_required')]
        #[Assert\Length(max: 100)]
        public readonly string $label,
        #[Assert\Choice(choices: AbstractPostTypeField::TYPES, message: 'backend.post_types.errors.field_type_invalid')]
        public readonly string $type,
        public readonly bool $required = false,
        public readonly bool $translatable = false,
        public readonly array $options = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isTranslatable(): bool
    {
        return $this->translatable;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
