<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class {{NAME}}Input implements {{NAME}}InputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'suite.{{PLURAL_SNAKE}}.errors.name_required')]
        #[Assert\Length(max: 150, maxMessage: 'suite.{{PLURAL_SNAKE}}.errors.name_too_long')]
        public readonly string $name,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }
}
