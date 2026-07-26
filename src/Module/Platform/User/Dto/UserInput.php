<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Dto;

use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Component\Validator\Constraints as Assert;

class UserInput implements UserInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.users.errors.name_required')]
        #[Assert\Length(max: 100, maxMessage: 'backend.users.errors.name_too_long')]
        public readonly string $name,
        #[Assert\NotBlank(message: 'backend.users.errors.email_required')]
        #[Assert\Email(message: 'backend.users.errors.email_invalid')]
        #[Assert\Length(max: 180, maxMessage: 'backend.users.errors.email_too_long')]
        public readonly string $email,
        #[Assert\NotBlank(message: 'backend.users.errors.role_required')]
        #[Assert\Choice(callback: [UserRoleEnum::class, 'allAssignableValues'], message: 'backend.users.errors.role_invalid')]
        public readonly string $role,
        public readonly string $locale = 'fr',
        #[Assert\Length(min: 8, minMessage: 'backend.users.errors.password_too_short')]
        public readonly ?string $password = null,
        public readonly ?int $managerId = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getManagerId(): ?int
    {
        return $this->managerId;
    }
}
