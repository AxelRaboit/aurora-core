<?php

declare(strict_types=1);

namespace Aurora\Module\General\Dashboard\Controller\Dev;

use Aurora\Module\General\Dashboard\View\OverviewViewBuilder;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dev/dashboard', name: 'dev_dashboard')]
#[IsGranted(UserRoleEnum::Dev->value)]
final class OverviewController extends AbstractController
{
    public function __construct(private readonly OverviewViewBuilder $viewBuilder) {}

    #[Route('', name: '')]
    public function __invoke(Request $request): Response
    {
        $payload = $this->viewBuilder->overviewPayload();

        if ('XMLHttpRequest' === $request->headers->get('X-Requested-With')) {
            return $this->json($payload);
        }

        return $this->render('@Dev/suite/index.html.twig', $this->viewBuilder->indexView($payload));
    }
}
