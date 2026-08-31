<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Dto;

interface UserInviteInputInterface
{
    public function getName(): string;

    public function getEmail(): string;

    public function getRole(): string;

    public function getMessage(): ?string;

    /**
     * Créer le compte sans contacter personne, connexion refusée jusqu'à son
     * activation - c'est elle qui envoie l'invitation.
     */
    public function isDisabled(): bool;
}
