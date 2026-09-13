<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a form may say about a deck.
 *
 * Not a `readonly class`, only readonly properties: a client project extends
 * this to add a field of its own, and a readonly class forbids the subclass
 * from declaring one. Same arrangement as `CustomerInput`.
 */
class DeckInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.decks.errors.title_required')]
        #[Assert\Length(max: 200, maxMessage: 'backend.studio.decks.errors.title_too_long')]
        public readonly string $title = '',
        #[Assert\Length(max: 500)]
        public readonly ?string $description = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $customerId = null,
        public readonly bool $isTemplate = false,
        /** The deck to copy the slides and the look from, when opening from a model. */
        public readonly ?int $fromTemplateId = null,
    ) {}
}
