<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\Link\Manager;

use Aurora\Module\Planning\Link\Entity\PlanningShareLinkInterface;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use DateTimeImmutable;

interface PlanningShareLinkManagerInterface
{
    /**
     * @param list<PlanningInterface> $calendars
     */
    public function create(
        array $calendars,
        string $label,
        PlanningShareLinkModeEnum $mode,
        ?DateTimeImmutable $expiresAt = null,
    ): PlanningShareLinkInterface;

    public function revoke(PlanningShareLinkInterface $link, ?DateTimeImmutable $at = null): void;

    /**
     * The link behind a token if it still works, or null.
     *
     * Null covers every reason at once - unknown, expired, revoked, wrong mode -
     * because the caller must not be able to tell them apart either.
     */
    public function resolveUsable(
        string $token,
        PlanningShareLinkModeEnum $mode,
        ?DateTimeImmutable $now = null,
    ): ?PlanningShareLinkInterface;
}
