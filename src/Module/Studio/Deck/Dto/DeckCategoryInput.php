<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class DeckCategoryInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.decks.categories.errors.name_required')]
        #[Assert\Length(max: 100)]
        public readonly string $name = '',
        #[Assert\Regex(
            pattern: '/^#[0-9a-fA-F]{6}$/',
            message: 'suite.studio.decks.categories.errors.color_invalid',
        )]
        public readonly ?string $color = null,
    ) {}
}
