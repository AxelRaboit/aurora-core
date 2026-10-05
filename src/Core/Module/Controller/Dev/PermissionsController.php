<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Controller\Dev;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Module\View\PermissionsViewBuilder;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dev/dashboard/permissions', name: 'dev_permissions')]
#[IsGranted(UserRoleEnum::Dev->value)]
final class PermissionsController extends AbstractController
{
    public function __construct(private readonly PermissionsViewBuilder $viewBuilder) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Request $request): Response
    {
        $payload = $this->viewBuilder->permissionsPayload();

        if ('XMLHttpRequest' === $request->headers->get('X-Requested-With')) {
            return $this->json($payload);
        }

        return $this->render('@Dev/suite/index.html.twig', $this->viewBuilder->indexView($payload));
    }
}
