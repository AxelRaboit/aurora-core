<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Menu\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Enum\MenuItemVisibilityEnum;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\Menu\Serializer\MenuSerializerInterface;

/**
 * Builds the Twig payload consumed by the admin menus screen.
 */
final readonly class MenusViewBuilder
{
    public function __construct(
        private MenuRepository $menuRepository,
        private MenuSerializerInterface $menuSerializer,
        private LocaleContextInterface $localeContext,
    ) {}

    /** @return array<string, mixed> */
    public function indexView(): array
    {
        return [
            'menus' => array_map(
                $this->menuSerializer->serialize(...),
                $this->menuRepository->findAllWithItems(),
            ),
            // The form edits every active locale at once, so it needs the list
            // rather than inferring it from the translations that exist.
            'locales' => $this->localeContext->getActiveLocales(),
            'targetTypes' => $this->targetTypes(),
            'visibilities' => $this->visibilities(),
        ];
    }

    /** @return list<array{value: string, labelKey: string, requiresTarget: bool, requiresUrl: bool}> */
    private function targetTypes(): array
    {
        return array_map(
            static fn (MenuItemTargetTypeEnum $case): array => [
                'value' => $case->value,
                'labelKey' => $case->labelKey(),
                'requiresTarget' => $case->requiresTargetId(),
                'requiresUrl' => $case->requiresCustomUrl(),
            ],
            MenuItemTargetTypeEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function visibilities(): array
    {
        return array_map(
            static fn (MenuItemVisibilityEnum $case): array => [
                'value' => $case->value,
                'labelKey' => $case->labelKey(),
            ],
            MenuItemVisibilityEnum::cases(),
        );
    }
}
