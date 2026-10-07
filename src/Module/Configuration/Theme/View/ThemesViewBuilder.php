<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\View;

use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
use Aurora\Module\Configuration\Theme\Repository\ThemeRepository;
use Aurora\Module\Configuration\Theme\Serializer\ThemeSerializerInterface;

/**
 * Builds the Twig payload for the admin themes page. Centralises the theme
 * list serialisation so the controller stays focused on JSON CRUD operations.
 */
final readonly class ThemesViewBuilder
{
    public function __construct(
        private ThemeRepository $themeRepository,
        private ThemeSerializerInterface $themeSerializer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function indexView(): array
    {
        return [
            'themes' => array_map($this->themeSerializer->serialize(...), $this->themeRepository->findAll()),
            // The offered families come from the enum and not from a list
            // copied into the JavaScript: their CSS stacks also serve to set
            // the selector's preview, and two copies of a stack drift apart as
            // soon as nobody compares them.
            'fonts' => ThemeFontEnum::choices(),
        ];
    }
}
