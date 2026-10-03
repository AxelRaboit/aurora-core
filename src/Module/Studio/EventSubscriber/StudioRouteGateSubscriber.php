<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\EventSubscriber;

use Aurora\Core\Module\EventSubscriber\AbstractModuleRouteGateSubscriber;
use Aurora\Module\Studio\StudioContext;

/**
 * Closes Studio's routes when the toggles behind them are off.
 *
 * Switching Studio or one of its parts off hid the menu entries and nothing
 * else: every screen, and every page a client opens through a link, still
 * answered. One gate per toggle, like Editorial's: turning off contracts
 * alone closes the contracts and leaves the spaces open.
 *
 * The client-facing pages go with the part they belong to. A space link, a
 * contract to sign or a shared deck is the studio speaking to a customer,
 * and a studio that switched the feature off is not answering any more.
 */
final readonly class StudioRouteGateSubscriber extends AbstractModuleRouteGateSubscriber
{
    public function __construct(private StudioContext $studioContext) {}

    protected function routeNamespaces(): array
    {
        return ['backend_studio_', 'workspace_', 'public_space', 'public_contract', 'public_deck', 'public_deliverable'];
    }

    protected function gates(): array
    {
        $backend = $this->studioContext->isBackendEnabled();

        return [
            'backend_studio_' => $backend,
            'backend_studio_customers' => $this->studioContext->areCustomersEnabled(),
            'backend_studio_contract' => $this->studioContext->areContractsEnabled(),
            'backend_studio_deck' => $this->studioContext->areDecksEnabled(),
            'backend_studio_deliverables' => $this->studioContext->areDeliverablesEnabled(),
            'backend_studio_spaces' => $this->studioContext->areSpacesEnabled(),
            'backend_studio_calendar' => $this->studioContext->areSpacesEnabled(),
            'workspace_' => $backend && $this->studioContext->areSpacesEnabled(),
            'public_space' => $backend && $this->studioContext->areSpacesEnabled(),
            'public_contract' => $backend && $this->studioContext->areContractsEnabled(),
            'public_deck' => $backend && $this->studioContext->areDecksEnabled(),
            // Un lien de lecture sert un livrable d'espace ou de Studio : le
            // contrôleur juge lequel, cf. DeliverableReadingController.
            'public_deliverable' => $backend,
        ];
    }
}
