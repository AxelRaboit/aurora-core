<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Dto;

interface SpaceContentColumnInputInterface
{
    public function getName(): string;

    /** Null means the step wears no colour, which is a choice and not an absence. */
    public function getColourSlot(): ?int;
}
