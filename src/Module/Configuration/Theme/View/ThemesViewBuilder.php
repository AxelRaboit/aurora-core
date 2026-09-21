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
            // Les familles proposées viennent de l'enum et pas d'une liste
            // recopiée dans le JavaScript : leurs piles CSS servent aussi à
            // composer l'aperçu du sélecteur, et deux copies d'une pile
            // divergent dès que personne ne les compare.
            'fonts' => ThemeFontEnum::choices(),
        ];
    }
}
