<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Configuration;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Which settings tabs the current user may see, in order.
 *
 * The filtering used to live inside `SettingsViewBuilder`, where it was the
 * only consumer. Three things need the answer now - the view builder, the
 * controller that has to validate a `{tab}` in the URL, and the module view
 * that lists the tabs in the side menu - and a rule about who may see what is
 * the last thing to keep three copies of.
 *
 * Moving it here is also what turns a tab into something the server enforces
 * rather than merely hides. A dev-only tab was previously left out of the
 * payload and the Vue layer discarded the fragment naming it; correct, but the
 * gate was in the browser. Now the tab has a URL, and refusing it is a 404
 * decided here.
 */
final readonly class SettingsTabAccess
{
    public function __construct(
        private SettingDefinitionRegistry $definitionRegistry,
        private ModuleAccessChecker $moduleAccessChecker,
        private AuthorizationCheckerInterface $security,
    ) {}

    /**
     * @return list<ConfigurationTab>
     */
    public function visibleTabs(): array
    {
        $visible = [];

        foreach ($this->definitionRegistry->getTabs() as $tab) {
            if ($this->isVisible($tab)) {
                $visible[] = $tab;
            }
        }

        return $visible;
    }

    public function isVisibleId(string $id): bool
    {
        return $this->find($id) instanceof ConfigurationTab;
    }

    public function find(string $id): ?ConfigurationTab
    {
        foreach ($this->visibleTabs() as $tab) {
            if ($tab->id === $id) {
                return $tab;
            }
        }

        return null;
    }

    /**
     * The tab a bare `/settings` should land on: the first one this user may
     * see, which is not always `general` - a client can contribute a tab with a
     * lower priority, and a user can lack the privilege for the first.
     */
    public function firstVisibleId(): ?string
    {
        return $this->visibleTabs()[0]->id ?? null;
    }

    private function isVisible(ConfigurationTab $tab): bool
    {
        if ($tab->devOnly && !$this->security->isGranted(UserRoleEnum::Dev->value)) {
            return false;
        }

        if (null !== $tab->requiredPrivilege && !$this->security->isGranted($tab->requiredPrivilege)) {
            return false;
        }

        // Hide tabs whose owning module is currently disabled. The settings
        // remain writable through the controller - so an admin re-enabling the
        // module has not lost their configuration - but the UI stays consistent
        // with what is actually reachable.
        if (null !== $tab->moduleToggle && !$this->moduleAccessChecker->isEnabled($tab->moduleToggle)) {
            return false;
        }

        // A tab with no fields and no component of its own has nothing to draw.
        return $tab->alwaysVisible || [] !== $tab->fields;
    }
}
