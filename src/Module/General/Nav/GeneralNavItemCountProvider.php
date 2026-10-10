<?php

declare(strict_types=1);

namespace Aurora\Module\General\Nav;

use Aurora\Core\Module\Nav\NavItemCountProviderInterface;
use Aurora\Module\General\Trash\Service\TrashOverviewService;
use Aurora\Module\General\Trash\View\TrashViewBuilder;

/**
 * The side menu's figure for « Corbeille »: what waits in every trash the
 * reader can open, counted by each trash with its own scope - the total the
 * trash screen would list.
 */
final readonly class GeneralNavItemCountProvider implements NavItemCountProviderInterface
{
    private const string TRASH = 'suite_general_trash';

    public function __construct(
        private TrashOverviewService $trashOverviewService,
        private TrashViewBuilder $trashViewBuilder,
    ) {}

    public function getCountedItemKeys(): array
    {
        return [self::TRASH];
    }

    public function countItem(string $itemKey): int
    {
        return self::TRASH === $itemKey
            ? $this->trashOverviewService->countAll($this->trashViewBuilder->enabledModules())
            : 0;
    }
}
