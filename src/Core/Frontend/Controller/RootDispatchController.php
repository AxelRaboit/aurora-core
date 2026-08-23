<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\Controller;

use Aurora\Core\Frontend\Contract\FrontendInterface;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Frontend\Service\Registry;
use Aurora\Core\Frontend\Service\Router;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class RootDispatchController extends AbstractController
{
    public function __construct(
        private readonly Router $router,
        private readonly Registry $registry,
        private readonly SettingRepository $settingRepository,
        private readonly Context $context,
    ) {}

    #[Route('/', name: 'frontend_root', priority: 10)]
    public function root(): RedirectResponse
    {
        $enabled = $this->firstEnabledFront();

        // No front is enabled (all of their module settings are OFF, or none
        // is registered). Send the visitor to /backend, which itself cascades
        // to /backend/profile when the Dashboard is also masked. Anonymous
        // visitors will hit the backend login firewall - the expected
        // entrypoint when there is no public site left to serve.
        if (!$enabled instanceof FrontendInterface) {
            return $this->redirectToRoute('backend_dashboard');
        }

        return $this->redirectToRoute($enabled->getHomeRoute(), ['locale' => $this->context->defaultLocale()]);
    }

    private function firstEnabledFront(): ?FrontendInterface
    {
        // Prefer the configured default if it is enabled, otherwise fall back
        // to whichever registered front is currently on (highest priority first
        // - Registry::all() is already sorted by priority).
        if ($this->registry->highest() instanceof FrontendInterface) {
            $default = $this->router->getDefault();
            if ($this->isFrontEnabled($default)) {
                return $default;
            }
        }

        foreach ($this->registry->all() as $front) {
            if ($this->isFrontEnabled($front)) {
                return $front;
            }
        }

        return null;
    }

    private function isFrontEnabled(FrontendInterface $front): bool
    {
        $settingKey = $front->getModuleSettingKey();

        return null === $settingKey || $this->settingRepository->getBoolean($settingKey, true);
    }
}
