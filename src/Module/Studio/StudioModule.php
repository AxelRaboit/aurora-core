<?php

declare(strict_types=1);

namespace Aurora\Module\Studio;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Nav\NavItem;
use Aurora\Core\Module\Nav\NavPermission;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Studio: what you sell to a client, and what you deliver to them.
 *
 * **The rule that decides what belongs here**, because a module named this
 * broadly has no other defence against becoming a drawer: Studio holds what is
 * sold and what is delivered. Not the tools it is made with. Customers,
 * contracts and deliverables belong; notes, documents and the calendar do not,
 * and each of those has a module of its own.
 *
 * It was called Accounting until 0.9.x, which named one corner of it and
 * excluded the rest. The rename was paid for by a question a narrower name
 * could not answer: a deck of slides is addressed to a customer, and a module
 * may not hold a relation to another module's entity. Either the two live
 * together or the relation cannot exist.
 *
 * **Amended in 0.9.176, deliberately.** The rule above read "what is sold and
 * what is delivered"; a client space is neither, it is the surface the delivery
 * happens on, and the first two words of the rule could not admit it. The rule
 * is now: Studio holds what is sold, what is delivered, and the surface it is
 * delivered on. The boundary it still refuses is the same one - a tool the work
 * is made with belongs to its own module: a space draws its own month and the
 * editorial calendar reads across spaces, but the dates also go to Planning
 * rather than a second agenda, and the documents are filed in the GED instead
 * of a second library.
 *
 * **Amended again after 2.0.0**, when the presentations became deliverables.
 * The rule now reads: Studio holds what is sold, what is delivered, and the
 * surface it is delivered on. What is delivered is a deliverable, written as a
 * page or composed as slides, and a standalone one belongs as much as one in a
 * space: a draft or a template kept for oneself before anything is sent is
 * still a thing being prepared for delivery, not a tool. Presentations stopped
 * being a destination of their own in that release; the slide engine lives
 * under `Deliverable/Slides`. Two former menu entries became views of what
 * they serve: the editorial calendar is a view of the spaces, reached from
 * their screen, and the contract trames are a tab of the contracts.
 *
 * The module has no landing page of its own. Every destination it owns is a
 * real screen, so a row in the menu always leads somewhere that shows
 * something - the shape Notes already uses. A section with a single "Studio"
 * tile in front of the list it would immediately redirect to is one click that
 * tells the reader nothing.
 */
final readonly class StudioModule implements ModuleInterface, ModuleToggleProviderInterface
{
    public function __construct(private StudioContext $studioContext) {}

    public function getId(): string
    {
        return 'studio';
    }

    public function getPermissions(): array
    {
        return [
            new NavPermission('studio.customers.view'),
            new NavPermission('studio.customers.create'),
            new NavPermission('studio.customers.edit'),
            new NavPermission('studio.customers.delete'),
            new NavPermission('studio.contract_templates.view'),
            new NavPermission('studio.contract_templates.create'),
            new NavPermission('studio.contract_templates.edit'),
            new NavPermission('studio.contract_templates.delete'),
            new NavPermission('studio.contracts.view'),
            new NavPermission('studio.contracts.create'),
            new NavPermission('studio.contracts.edit'),
            new NavPermission('studio.contracts.delete'),
            new NavPermission('studio.contracts.send'),
            new NavPermission('studio.contracts.countersign'),
            new NavPermission('studio.deliverables.view'),
            new NavPermission('studio.deliverables.create'),
            new NavPermission('studio.deliverables.edit'),
            new NavPermission('studio.deliverables.delete'),
            new NavPermission('studio.deliverables.share'),
            new NavPermission('studio.spaces.view'),
            new NavPermission('studio.spaces.create'),
            new NavPermission('studio.spaces.edit'),
            new NavPermission('studio.spaces.delete'),
            new NavPermission('studio.spaces.share'),
        ];
    }

    public function getNavSections(): array
    {
        if (!$this->studioContext->isSuiteEnabled()) {
            return [];
        }

        $items = [];

        if ($this->studioContext->areSpacesEnabled()) {
            // Before the customer list, and not alphabetically: a space is
            // opened every day and a legal identity block is filled in once.
            // The editorial calendar is a view of the spaces, reached from
            // their screen: one entry, not two.
            $items[] = $this->spacesNavItem();
        }

        if ($this->studioContext->areCustomersEnabled()) {
            $items[] = $this->customersNavItem();
        }

        if ($this->studioContext->areContractsEnabled()) {
            // One entry: the trames are a tab of it, the documents contracts
            // are drawn from rather than a destination of their own.
            $items[] = $this->contractsNavItem();
        }

        // Les documents qu'on écrit pour quelqu'un, pages et présentations :
        // une seule entrée depuis que les présentations sont des livrables.
        if ($this->studioContext->areDeliverablesEnabled()) {
            $items[] = $this->deliverablesNavItem();
        }

        if ([] === $items) {
            return [];
        }

        return [new NavSection('studio', $items, priority: 45)];
    }

    public function getCatalogNavSections(): array
    {
        return [new NavSection('studio', [
            $this->spacesNavItem(),
            $this->customersNavItem(),
            $this->contractsNavItem(),
            $this->deliverablesNavItem(),
        ], priority: 45)];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::StudioSuite->toToggle(),
            ModuleParameterEnum::StudioCustomers->toToggle(),
            ModuleParameterEnum::StudioContracts->toToggle(),
            ModuleParameterEnum::StudioDeliverables->toToggle(),
            ModuleParameterEnum::StudioSpaces->toToggle(),
        ];
    }

    private function contractsNavItem(): NavItem
    {
        return new NavItem(
            'suite_studio_contracts',
            'suite.nav.studio_contracts',
            'file-signature',
            requiredPrivilege: 'studio.contracts.view',
            // Lit aussi les pages des trames, son second onglet : sans ce
            // préfixe, ouvrir une trame éteignait l'entrée.
            activeRoutePrefix: 'suite_studio_contract',
            descriptionKey: 'suite.nav.studio_contracts_description',
        );
    }

    /** The icon every other screen gives a deliverable: dashboard, search, trash, and the tab of a space. */
    private function deliverablesNavItem(): NavItem
    {
        return new NavItem(
            'suite_studio_deliverables',
            'suite.nav.studio_deliverables',
            'notebook-text',
            requiredPrivilege: 'studio.deliverables.view',
            descriptionKey: 'suite.nav.studio_deliverables_description',
        );
    }

    private function spacesNavItem(): NavItem
    {
        return new NavItem(
            'suite_studio_spaces',
            'suite.nav.studio_spaces',
            'panels-top-left',
            requiredPrivilege: 'studio.spaces.view',
            descriptionKey: 'suite.nav.studio_spaces_description',
        );
    }

    private function customersNavItem(): NavItem
    {
        return new NavItem(
            'suite_studio_customers',
            'suite.nav.studio_customers',
            'building-2',
            requiredPrivilege: 'studio.customers.view',
            descriptionKey: 'suite.nav.studio_customers_description',
        );
    }
}
