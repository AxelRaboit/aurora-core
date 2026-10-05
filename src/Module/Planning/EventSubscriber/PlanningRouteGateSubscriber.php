<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\EventSubscriber;

use Aurora\Core\Module\EventSubscriber\AbstractModuleRouteGateSubscriber;
use Aurora\Module\Planning\PlanningContext;

/**
 * Closes the calendar's routes when it is switched off.
 *
 * The screens, and the addresses handed out from them: a feed subscribed in
 * a phone and a shared page are the calendar still publishing, and a
 * calendar switched off should stop.
 */
final readonly class PlanningRouteGateSubscriber extends AbstractModuleRouteGateSubscriber
{
    public function __construct(private PlanningContext $planningContext) {}

    protected function routeNamespaces(): array
    {
        return ['suite_planning_', 'planning_feed', 'planning_share'];
    }

    protected function gates(): array
    {
        $enabled = $this->planningContext->isSuiteEnabled();

        return [
            'suite_planning_' => $enabled,
            'planning_feed' => $enabled,
            'planning_share' => $enabled,
        ];
    }
}
