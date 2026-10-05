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
 * contracts and presentations belong; notes, documents and the calendar do not,
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
            new NavPermission('studio.decks.view'),
            new NavPermission('studio.decks.create'),
            new NavPermission('studio.decks.edit'),
            new NavPermission('studio.decks.delete'),
            new NavPermission('studio.decks.share'),
            new NavPermission('studio.deck_categories.manage'),
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
            $items[] = $this->spacesNavItem();
            $items[] = $this->calendarNavItem();
        }

        if ($this->studioContext->areCustomersEnabled()) {
            $items[] = $this->customersNavItem();
        }

        if ($this->studioContext->areContractsEnabled()) {
            // Contracts before the trames they are built from: the list read
            // every week comes before the documents edited twice a year.
            $items[] = $this->contractsNavItem();
            $items[] = $this->contractTemplatesNavItem();
        }

        if ($this->studioContext->areDecksEnabled()) {
            $items[] = $this->decksNavItem();
        }

        // Après les présentations : l'autre document qu'on écrit pour
        // quelqu'un, celui qu'on lui envoie à lire plutôt qu'on lui montre.
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
            $this->calendarNavItem(),
            $this->customersNavItem(),
            $this->contractsNavItem(),
            $this->contractTemplatesNavItem(),
            $this->decksNavItem(),
            $this->deliverablesNavItem(),
        ], priority: 45)];
    }

    public function getToggles(): array
    {
        return [
            ModuleParameterEnum::StudioSuite->toToggle(),
            ModuleParameterEnum::StudioCustomers->toToggle(),
            ModuleParameterEnum::StudioContracts->toToggle(),
            ModuleParameterEnum::StudioDecks->toToggle(),
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
            descriptionKey: 'suite.nav.studio_contracts_description',
        );
    }

    private function contractTemplatesNavItem(): NavItem
    {
        return new NavItem(
            'suite_studio_contract_templates',
            'suite.nav.studio_contract_templates',
            'scroll-text',
            requiredPrivilege: 'studio.contract_templates.view',
            descriptionKey: 'suite.nav.studio_contract_templates_description',
        );
    }

    private function decksNavItem(): NavItem
    {
        return new NavItem(
            'suite_studio_decks',
            'suite.nav.studio_decks',
            'presentation',
            requiredPrivilege: 'studio.decks.view',
            // Les pages d'une présentation s'appellent `suite_studio_deck`,
            // au singulier : l'éditeur, le mode présentateur, l'impression.
            // Sans ce préfixe, ouvrir une présentation éteignait le menu.
            activeRoutePrefix: 'suite_studio_deck',
            descriptionKey: 'suite.nav.studio_decks_description',
        );
    }

    private function deliverablesNavItem(): NavItem
    {
        return new NavItem(
            'suite_studio_deliverables',
            'suite.nav.studio_deliverables',
            'file-check',
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

    /** Next to the spaces it reads from: the same work, laid on one month. */
    private function calendarNavItem(): NavItem
    {
        return new NavItem(
            'suite_studio_calendar',
            'suite.nav.studio_calendar',
            'calendar-range',
            requiredPrivilege: 'studio.spaces.view',
            descriptionKey: 'suite.nav.studio_calendar_description',
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
