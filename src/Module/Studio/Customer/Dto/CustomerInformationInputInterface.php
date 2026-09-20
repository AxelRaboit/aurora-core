<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

interface CustomerInformationInputInterface
{
    public function getLegalName(): string;

    public function getSiret(): ?string;

    public function getSiren(): ?string;

    public function getPhone(): ?string;

    public function getLandline(): ?string;

    public function getEmail(): ?string;

    public function getPostalAddress(): ?string;

    /** @return list<array{label: string, url: string}> */
    public function getLinks(): array;

    public function getNotes(): ?string;
}
