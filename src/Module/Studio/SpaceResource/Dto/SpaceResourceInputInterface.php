<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceResource\Dto;

use Aurora\Module\Studio\SpaceResource\Enum\SpaceResourceKindEnum;

interface SpaceResourceInputInterface
{
    public function getKind(): SpaceResourceKindEnum;

    public function getLabel(): string;

    public function getUrl(): ?string;

    public function getBody(): ?string;

    public function getEmail(): ?string;

    public function getPhone(): ?string;

    public function isVisibleToClient(): bool;
}
