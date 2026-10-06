<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Dto;

use Aurora\Module\Platform\User\Enum\UserTypeEnum;

interface UserInviteInputInterface
{
    public function getName(): string;

    public function getEmail(): string;

    public function getRole(): string;

    public function getMessage(): ?string;

    /**
     * Create the account without contacting anyone, login refused until it is
     * enabled - enabling it is what sends the invitation.
     */
    public function isDisabled(): bool;

    /** The administration or the public site: the two populations are distinct. */
    public function getType(): UserTypeEnum;
}
