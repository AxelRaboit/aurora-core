<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\Contract;

interface FrontendInterface
{
    public function getSlug(): string;

    public function getLabel(): string;

    public function getHomeRoute(): string;

    public function getPriority(): int;

    /** Returns the ApplicationParameterEnum key that enables/disables this front, or null if always available. */
    public function getModuleSettingKey(): ?string;

    /**
     * Route-name prefixes that belong to this front. Used by
     * {@see FrontendRouteGateSubscriber}
     * to 404 every front route when the front is disabled.
     *
     * Return an empty list if the front uses route names that cannot be
     * matched by prefix - the gate will not enforce 404 then (the
     * per-controller IsGranted / route-level guards still apply).
     *
     * @return list<string>
     */
    public function getRoutePrefixes(): array;
}
