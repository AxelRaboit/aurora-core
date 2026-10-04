<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Ce qu'on écrit d'une catégorie de livrables : son nom et sa couleur. */
class DeliverableCategoryInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.studio.deliverables.categories.errors.name_required')]
        #[Assert\Length(max: 100, maxMessage: 'backend.studio.deliverables.categories.errors.name_too_long')]
        public readonly string $name = '',
        #[Assert\Regex(
            pattern: '/^#[0-9a-fA-F]{6}$/',
            message: 'backend.studio.deliverables.categories.errors.color_invalid',
        )]
        public readonly ?string $color = null,
    ) {}
}
