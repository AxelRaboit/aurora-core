<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\View;

use Aurora\Core\Module\Service\PermissionRegistry;
use Aurora\Core\Module\Toggle\ModuleToggle;
use Aurora\Core\Module\Toggle\ModuleToggleRegistry;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\Agency\Entity\AgencyInterface;
use Aurora\Module\Platform\Agency\Repository\AgencyRepository;
use Aurora\Module\Platform\Service\Entity\ServiceInterface;
use Aurora\Module\Platform\Service\Repository\ServiceRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class UsersViewBuilder
{
    /**
     * Display priority per module/group id (lower = first). Mirrors the
     * NavSection priorities used by the sidemenu, so the Privileges modal
     * and Module-Access modal show modules in the same order users see
     * in the left menu. Unknown ids default to {@see self::UNKNOWN_PRIORITY}
     * which places them at the end of the list (typical for client modules).
     */
    private const array MODULE_PRIORITY = [
        'general' => 10,
        'platform' => 20,
        'media' => 22,
        'configuration' => 25,
        'editorial' => 30,
        'ged' => 35,
    ];

    private const int UNKNOWN_PRIORITY = 500;

    /** @var array<string, string> module ID → its top-level toggle key, from the registry */
    private array $moduleToggleKeys;

    public function __construct(
        private PermissionRegistry $permissionRegistry,
        private SettingRepository $settingRepository,
        private AgencyRepository $agencyRepository,
        private ServiceRepository $serviceRepository,
        private TranslatorInterface $translator,
        private ModuleToggleRegistry $moduleToggleRegistry,
    ) {
        $keys = [];
        foreach ($this->moduleToggleRegistry->getTopLevel() as $toggle) {
            if (null !== $toggle->moduleId) {
                $keys[$toggle->moduleId] = $toggle->key;
            }
        }

        $this->moduleToggleKeys = $keys;
    }

    /**
     * @return array<string, mixed>
     */
    public function indexView(bool $isDev, ?User $currentUser, bool $canManageDisabledModules = false): array
    {
        $selectableRoles = $isDev
            ? [UserRoleEnum::Dev, ...UserRoleEnum::selectableForAdmin()]
            : UserRoleEnum::selectableForAdmin();

        $translator = $this->translator;
        $roles = array_map(
            static fn (UserRoleEnum $role): array => ['value' => $role->value, 'label' => $translator->trans($role->getLabelKey())],
            $selectableRoles,
        );

        $currentUserPriority = $currentUser instanceof User
            ? UserRoleEnum::highestPriorityForRoles($currentUser->getRoles())
            : 0;

        // All privileges grouped by module, filtered to only enabled modules.
        $privilegesByModule = [];
        foreach ($this->permissionRegistry->byModule() as $moduleId => $privileges) {
            if ([] === $privileges) {
                continue;
            }

            $toggleKey = $this->moduleToggleKeys[$moduleId] ?? null;
            if (null !== $toggleKey && !$this->settingRepository->getBoolean($toggleKey, true)) {
                continue;
            }

            $privilegesByModule[] = [
                'module' => $moduleId,
                'privileges' => $privileges,
            ];
        }

        usort($privilegesByModule, fn (array $a, array $b): int => $this->priorityFor($a['module']) <=> $this->priorityFor($b['module']));

        $agencies = array_map(
            static fn (AgencyInterface $agency): array => ['value' => (string) $agency->getId(), 'label' => $agency->getName()],
            $this->agencyRepository->findAllAlphabetical(),
        );

        $services = array_map(
            static fn (ServiceInterface $service): array => ['value' => (string) $service->getId(), 'label' => $service->getName()],
            $this->serviceRepository->findAllAlphabetical(),
        );

        // Modules currently enabled globally — surfaced to the per-user
        // disabled-modules picker as a hierarchical tree
        // (top-level → sub-modules, recursive). Source = ModuleToggleRegistry,
        // so aurora-client modules can plug their own toggles without
        // patching this builder. Sub-toggles whose global setting is OFF are
        // filtered out — they cannot be enabled per-user.
        $modulesForAccess = [];
        foreach ($this->moduleToggleRegistry->getTopLevel() as $toggle) {
            if (!$this->settingRepository->getBoolean($toggle->key, true)) {
                continue;
            }

            $modulesForAccess[] = $this->buildToggleNode($toggle);
        }

        usort($modulesForAccess, fn (array $a, array $b): int => $this->priorityFor((string) $a['moduleId']) <=> $this->priorityFor((string) $b['moduleId']));

        return [
            'roles' => $roles,
            'isDev' => $isDev,
            'currentUserPriority' => $currentUserPriority,
            'privilegesByModule' => $privilegesByModule,
            'modulesForAccess' => $modulesForAccess,
            'canManageDisabledModules' => $canManageDisabledModules,
            'agencies' => $agencies,
            'services' => $services,
        ];
    }

    private function priorityFor(string $moduleId): int
    {
        return self::MODULE_PRIORITY[$moduleId] ?? self::UNKNOWN_PRIORITY;
    }

    /**
     * Builds the hierarchical payload for a single toggle (top-level or sub-),
     * including its enabled children recursively. Sub-toggles whose global
     * setting is OFF are filtered out — they cannot be enabled per-user.
     *
     * @return array<string, mixed>
     */
    private function buildToggleNode(ModuleToggle $toggle): array
    {
        $children = [];
        foreach ($this->moduleToggleRegistry->getChildrenOf($toggle->key) as $child) {
            if (!$this->settingRepository->getBoolean($child->key, true)) {
                continue;
            }

            $children[] = $this->buildToggleNode($child);
        }

        return [
            'key' => $toggle->key,
            'moduleId' => $toggle->moduleId,
            'label' => $this->translator->trans($toggle->labelKey),
            'description' => $this->translator->trans($toggle->descriptionKey),
            'children' => $children,
        ];
    }
}
