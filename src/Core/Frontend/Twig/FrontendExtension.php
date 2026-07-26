<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\Twig;

use Aurora\Core\Frontend\Service\Registry;
use Aurora\Core\Frontend\Service\Router;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use RuntimeException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig accessors for the frontend registry. Currently exposes whether
 * at least one front is enabled, so the sidemenu can hide the "Voir le
 * site" link when every front has been masked from the dev panel.
 */
final readonly class FrontendExtension
{
    public function __construct(
        private Registry $registry,
        private SettingRepository $settingRepository,
        private Router $router,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    #[AsTwigFunction(name: 'has_enabled_fronts')]
    public function hasEnabledFronts(): bool
    {
        foreach ($this->registry->all() as $front) {
            $settingKey = $front->getModuleSettingKey();
            if (null === $settingKey || $this->settingRepository->getBoolean($settingKey, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Home path of whichever front is currently active (see
     * {@see Router::getDefault()}) — lets shared frontend chrome (the
     * default theme's layout, site header links, locale switcher) link
     * "home" without hardcoding a specific module's route name. Falls
     * back to "#" if no front is registered at all.
     */
    #[AsTwigFunction(name: 'default_front_home_path')]
    public function defaultFrontHomePath(string $locale): string
    {
        try {
            $front = $this->router->getDefault();
        } catch (RuntimeException) {
            return '#';
        }

        return $this->urlGenerator->generate($front->getHomeRoute(), ['locale' => $locale]);
    }
}
