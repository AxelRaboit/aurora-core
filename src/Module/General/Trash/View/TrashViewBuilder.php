<?php

declare(strict_types=1);

namespace Aurora\Module\General\Trash\View;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Core\Trash\TrashItem;
use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\General\Trash\Service\TrashOverviewService;

/**
 * The payload of the trash screen.
 *
 * Names the module toggles as plain settings keys, like the dashboard does, so
 * this shell never imports a business module's parameter enum and cannot
 * disagree with the side menu about what is switched on.
 *
 * The action addresses are generated here, with `__id__` where the row's id
 * goes, which is the shape every other screen in the back office uses.
 */
final readonly class TrashViewBuilder
{
    /**
     * Module id → the settings key gating it.
     *
     * @var array<string, string>
     */
    private const array MODULE_TOGGLES = [
        'ged' => 'modules_ged_backend',
        'editorial' => 'modules_editorial_backend',
        'notes' => 'modules_notes_backend',
    ];

    public function __construct(
        private TrashOverviewService $trashOverviewService,
        private ModuleAccessChecker $moduleAccessChecker,
        private SettingRepository $settingRepository,
        private PathTemplateGenerator $pathTemplates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        return [
            'trashes' => $this->trashes(),
            'retentionDays' => (int) $this->settingRepository->get(
                ApplicationParameterEnum::TrashAutoPurgeDays->value,
                ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue(),
            ),
        ];
    }

    /**
     * The same list the page was rendered with, for the screen to read back
     * after a restore or a deletion. One address rather than five: the rows
     * of one trash can change what another shows, since a folder released at
     * the root moves documents.
     *
     * @return list<array<string, mixed>>
     */
    public function trashes(): array
    {
        $enabled = [];
        foreach (self::MODULE_TOGGLES as $moduleId => $toggle) {
            if ($this->moduleAccessChecker->isEnabled($toggle)) {
                $enabled[] = $moduleId;
            }
        }

        return array_map($this->present(...), $this->trashOverviewService->getSummaries($enabled));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TrashSummary $summary): array
    {
        return [
            'key' => $summary->key,
            'labelKey' => $summary->labelKey,
            'icon' => $summary->icon,
            'count' => $summary->count,
            'oldestDeletedAt' => $summary->oldestDeletedAt?->format(DATE_ATOM),
            'restorePath' => $this->pathFor($summary->restoreRoute),
            'forceDeletePath' => $this->pathFor($summary->forceDeleteRoute),
            'emptyTrashPath' => $this->pathFor($summary->emptyTrashRoute, withId: false),
            'actionPrivilege' => $summary->actionPrivilege,
            'items' => array_map(
                static fn (TrashItem $item): array => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'deletedAt' => $item->deletedAt?->format(DATE_ATOM),
                    'context' => $item->context,
                ],
                $summary->items,
            ),
        ];
    }

    /**
     * The `__id__` hole goes through {@see PathTemplateGenerator}: these
     * routes declare `id` as `\d+`, and generating one straight refuses while
     * the page is rendering - a 500 in place of the screen.
     */
    private function pathFor(?string $route, bool $withId = true): ?string
    {
        if (null === $route) {
            return null;
        }

        return $this->pathTemplates->generate($route, $withId ? ['id' => '__id__'] : []);
    }
}
