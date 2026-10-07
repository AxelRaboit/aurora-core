<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\View;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Enum\ModuleToggleTypeEnum;
use Aurora\Core\Module\Nav\NavSection;
use Aurora\Core\Module\Toggle\ModuleToggle;
use Aurora\Core\Module\Toggle\ModuleToggleRegistry;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ModulesViewBuilder
{
    /** @param iterable<ModuleInterface> $modules */
    public function __construct(
        private SettingRepository $settingRepository,
        private iterable $modules,
        private TranslatorInterface $translator,
        private ModuleToggleRegistry $moduleToggleRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function modulesPayload(): array
    {
        $catalogByModuleId = $this->buildCatalogByModuleId();
        $parameters = [];

        // Order the module cards like the sidemenu: by each module's lowest
        // NavSection priority (the same source the sidemenu sorts on), so both
        // views list modules in the same order. Standalone toggles with no
        // module (e.g. a *Frontend switch) sort last.
        $priorityByModuleId = [];
        foreach ($catalogByModuleId as $moduleId => $sections) {
            $priorityByModuleId[$moduleId] = [] === $sections
                ? PHP_INT_MAX
                : min(array_map(static fn (NavSection $section): int => $section->priority, $sections));
        }

        $topLevel = $this->moduleToggleRegistry->getDisplayTopLevel();
        usort(
            $topLevel,
            static fn (ModuleToggle $left, ModuleToggle $right): int => ($priorityByModuleId[$left->moduleId] ?? PHP_INT_MAX) <=> ($priorityByModuleId[$right->moduleId] ?? PHP_INT_MAX),
        );

        // Single registry-driven path: every module (core or aurora-client)
        // contributes its toggles via ModuleToggleProviderInterface. Top-level
        // toggles (moduleId set) become module cards; sub-toggles nest under
        // them structurally via displayParentKey (falling back to the cascade
        // parentKey for client toggles). No central enum iteration.
        foreach ($topLevel as $toggle) {
            $navItems = [];
            $moduleId = $toggle->moduleId;

            if (null !== $moduleId && isset($catalogByModuleId[$moduleId])) {
                foreach ($catalogByModuleId[$moduleId] as $section) {
                    foreach ($section->items as $item) {
                        $navItems[] = ['labelKey' => $item->labelKey, 'icon' => $item->icon];
                    }
                }
            }

            $subModules = array_map(
                $this->toggleToArray(...),
                $this->moduleToggleRegistry->getDisplayChildrenOf($toggle->key),
            );

            $parameters[] = [...$this->toggleToArray($toggle), 'navItems' => $navItems, 'subModules' => $subModules];
        }

        return ['parameters' => $parameters];
    }

    /** @return array<string, mixed> */
    private function toggleToArray(ModuleToggle $toggle): array
    {
        return [
            'key' => $toggle->key,
            'label' => $this->translator->trans($toggle->labelKey),
            'description' => $this->translator->trans($toggle->descriptionKey),
            'value' => $this->settingRepository->get($toggle->key, '1'),
            'requires' => $toggle->parentKey,
            'type' => ModuleToggleTypeEnum::fromKey($toggle->key)->value,
        ];
    }

    /** @return array<string, NavSection[]> */
    private function buildCatalogByModuleId(): array
    {
        $catalog = [];
        foreach ($this->modules as $module) {
            $catalog[$module->getId()] = $module->getCatalogNavSections();
        }

        return $catalog;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function indexView(array $payload): array
    {
        return [
            'tab' => 'modules',
            'modules' => $payload,
        ];
    }
}
