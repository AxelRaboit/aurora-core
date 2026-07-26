<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Dto;

interface UserInputInterface
{
    public function getName(): string;

    public function getEmail(): string;

    public function getRole(): string;

    public function getLocale(): string;

    public function getPassword(): ?string;

    public function getManagerId(): ?int;
}
