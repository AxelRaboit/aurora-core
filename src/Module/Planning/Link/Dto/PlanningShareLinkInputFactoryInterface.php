<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Dto;

interface PlanningShareLinkInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): PlanningShareLinkInput;
}
