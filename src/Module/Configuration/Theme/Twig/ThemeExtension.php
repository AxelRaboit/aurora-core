<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Theme\Twig;

use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Configuration\Theme\Service\ThemeStyleRenderer;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Exposes the {@see ThemeContext} as the `themeContext` Twig global so layouts and
 * partials can read it without having to be passed it from every controller, and
 * the {@see ThemeStyleRenderer} as `themeStyles` for everything that writes CSS.
 *
 * Front controllers that need a per-request override can still set their own
 * `themeContext` variable and it will shadow the global.
 */
final class ThemeExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ThemeContext $themeContext,
        private readonly ThemeStyleRenderer $themeStyleRenderer,
    ) {}

    public function getGlobals(): array
    {
        return ['themeContext' => $this->themeContext, 'themeStyles' => $this->themeStyleRenderer];
    }
}
