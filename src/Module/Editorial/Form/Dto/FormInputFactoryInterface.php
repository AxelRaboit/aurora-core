<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Dto;

interface FormInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): FormInputInterface;
}
