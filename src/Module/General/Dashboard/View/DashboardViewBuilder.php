<?php

declare(strict_types=1);

namespace Aurora\Module\General\Dashboard\View;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\General\Dashboard\Service\StatsService;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Builds the Twig payload for the suite dashboard.
 *
 * A module joins by adding a line to MODULES below and a
 * `*-panel.register.js` on the Vue side, which fills the panel registry before
 * any app mounts. (That register file replaced a `MODULE_DEFINITIONS` constant
 * this comment still named.) Its figures reach `stats` through a
 * DashboardStatsProviderInterface, which {@see StatsService} filters by the
 * module ids reported enabled here. With nothing listed, the Vue shell draws
 * its empty state.
 *
 * The toggle is named as a plain settings key so this shell never imports a
 * business module's parameter enum, and passed as a string to
 * {@see ModuleAccessChecker}, which accepts one - so the cascade and the
 * per-user masking still apply, and the dashboard cannot disagree with the
 * side menu about what is switched on.
 */
final readonly class DashboardViewBuilder
{
    /**
     * Module id → the settings key gating it, and the privilege that lets
     * somebody look at what it counts.
     *
     * One table rather than two: with a separate list of privileges, a module
     * added to the toggles and forgotten there showed its panel to everybody.
     * The id is what a stats provider returns from `getModuleKey()` and what
     * the Vue definitions match on, and the screen draws only the panels this
     * table says yes to.
     *
     * Planning fournissait ses chiffres depuis le début sans être listé ici :
     * ses chiffres n'étaient jamais demandés, et le calendrier annonçait zéro
     * calendrier et zéro retard. Un module absent de cette table n'a plus de
     * panneau du tout.
     *
     * @var array<string, array{toggle: string, privilege: string}>
     */
    private const array MODULES = [
        'editorial' => ['toggle' => 'modules_editorial_suite', 'privilege' => 'editorial.posts.view'],
        'ged' => ['toggle' => 'modules_ged_suite', 'privilege' => 'ged.documents.view'],
        'platform' => ['toggle' => 'modules_platform_suite', 'privilege' => 'platform.users.manage'],
        'planning' => ['toggle' => 'modules_planning_suite', 'privilege' => 'planning.calendars.view'],
        'studio' => ['toggle' => 'modules_studio_suite', 'privilege' => 'studio.spaces.view'],
    ];

    public function __construct(
        private StatsService $statsService,
        private ModuleAccessChecker $moduleAccessChecker,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        $enabledModules = [];
        foreach (self::MODULES as $moduleId => $module) {
            $enabledModules[$moduleId] = $this->moduleAccessChecker->isEnabled($module['toggle'])
                && $this->authorizationChecker->isGranted($module['privilege']);
        }

        return [
            'enabledModules' => $enabledModules,
            'stats' => $this->statsService->getStats(array_keys(array_filter($enabledModules))),
        ];
    }
}
