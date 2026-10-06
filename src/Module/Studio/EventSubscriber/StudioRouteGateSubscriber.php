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
        return ['suite_studio_', 'workspace_', 'public_space', 'public_contract', 'public_deck', 'public_deliverable'];
    }

    protected function gates(): array
    {
        $suite = $this->studioContext->isSuiteEnabled();

        return [
            'suite_studio_' => $suite,
            'suite_studio_customers' => $this->studioContext->areCustomersEnabled(),
            'suite_studio_contract' => $this->studioContext->areContractsEnabled(),
            'suite_studio_deck' => $this->studioContext->areDecksEnabled(),
            'suite_studio_deliverables' => $this->studioContext->areDeliverablesEnabled(),
            // The editorial calendar included: it lives under the spaces now.
            'suite_studio_spaces' => $this->studioContext->areSpacesEnabled(),
            'workspace_' => $suite && $this->studioContext->areSpacesEnabled(),
            'public_space' => $suite && $this->studioContext->areSpacesEnabled(),
            'public_contract' => $suite && $this->studioContext->areContractsEnabled(),
            'public_deck' => $suite && $this->studioContext->areDecksEnabled(),
            // Un lien de lecture sert un livrable d'espace ou de Studio : le
            // contrôleur juge lequel, cf. DeliverableReadingController.
            'public_deliverable' => $suite,
            // Les polices ne servent que les présentations, qui sont des
            // livrables de Studio : elles suivent le module Livrables, jamais
            // celui des présentations.
            'public_deliverable_font' => $suite && $this->studioContext->areDeliverablesEnabled(),
        ];
    }
}
