<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** What gets written for a deliverable category: its name and its colour. */
class DeliverableCategoryInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.studio.deliverables.categories.errors.name_required')]
        #[Assert\Length(max: 100, maxMessage: 'suite.studio.deliverables.categories.errors.name_too_long')]
        public readonly string $name = '',
        #[Assert\Regex(
            pattern: '/^#[0-9a-fA-F]{6}$/',
            message: 'suite.studio.deliverables.categories.errors.color_invalid',
        )]
        public readonly ?string $color = null,
    ) {}
}
