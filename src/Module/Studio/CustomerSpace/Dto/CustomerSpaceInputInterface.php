<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Dto;

use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;

interface CustomerSpaceInputInterface
{
    public function getName(): string;

    public function getDescription(): ?string;

    public function getCustomerId(): ?int;

    public function getProspectName(): ?string;

    public function getProspectEmail(): ?string;

    public function getStatus(): CustomerSpaceStatusEnum;

    /** Null means "choose one for me", which is what the create form sends. */
    public function getColourSlot(): ?int;

    public function getTimezone(): string;

    /**
     * The accounts on this space, with their role.
     *
     * The whole set every time, not a diff: the form edits a list and sends
     * what it now holds, so the Manager can reconcile without the page having
     * to track what it removed.
     *
     * @return list<array{userId: int, role: string}>
     */
    public function getMembers(): array;
}
