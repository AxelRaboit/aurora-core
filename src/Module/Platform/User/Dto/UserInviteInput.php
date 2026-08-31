<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Dto;

use Aurora\Module\Platform\Auth\Validator\UniqueEmail;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Symfony\Component\Validator\Constraints as Assert;

class UserInviteInput implements UserInviteInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.users.errors.name_required')]
        #[Assert\Length(max: 100, maxMessage: 'backend.users.errors.name_too_long')]
        public readonly string $name,
        #[Assert\NotBlank(message: 'backend.users.errors.email_required')]
        #[Assert\Email(message: 'backend.users.errors.email_invalid')]
        #[Assert\Length(max: 180, maxMessage: 'backend.users.errors.email_too_long')]
        #[UniqueEmail(message: 'backend.users.errors.email_taken')]
        public readonly string $email,
        #[Assert\NotBlank(message: 'backend.users.errors.role_required')]
        #[Assert\Choice(callback: [UserRoleEnum::class, 'allAssignableValues'], message: 'backend.users.errors.role_invalid')]
        public readonly string $role,
        public readonly ?string $message = null,
        /**
         * Créer le compte sans contacter personne.
         *
         * Pour quelqu'un qui arrive plus tard : le compte existe et la connexion
         * lui est refusée, mais aucun jeton n'est émis et aucun mail ne part -
         * `invitedAt` reste donc nul, ce qui est ce qui distingue « jamais
         * contacté » de « désactivé après avoir été actif ». C'est l'activation
         * du compte qui envoie l'invitation.
         */
        public readonly bool $disabled = false,
        /**
         * L'administration ou le site public.
         *
         * `role` reste validé dans les deux cas - il est toujours envoyé - mais
         * il est ignoré pour un compte frontend : le manager y force ROLE_USER,
         * parce que le frontend n'a qu'un rôle et que ce n'est pas un choix.
         */
        #[Assert\Choice(callback: [UserTypeEnum::class, 'values'], message: 'backend.users.errors.type_invalid')]
        public readonly string $type = 'backend',
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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function getType(): UserTypeEnum
    {
        return UserTypeEnum::from($this->type);
    }
}
