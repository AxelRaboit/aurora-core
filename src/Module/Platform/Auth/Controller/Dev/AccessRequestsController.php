<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Controller\Dev;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Platform\Auth\Entity\AccessRequest;
use Aurora\Module\Platform\Auth\Manager\AccessRequestManagerInterface;
use Aurora\Module\Platform\Auth\Repository\AccessRequestRepository;
use Aurora\Module\Platform\Auth\View\DevAccessRequestsViewBuilder;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/dev/dashboard/access-requests', name: 'dev_access_requests')]
#[IsGranted(UserRoleEnum::Dev->value)]
final class AccessRequestsController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly AccessRequestManagerInterface $accessRequestManager,
        private readonly UserManagerInterface $userManager,
        private readonly TranslatorInterface $translator,
        private readonly DevAccessRequestsViewBuilder $viewBuilder,
    ) {}

    #[Route('', name: '')]
    public function index(PaginationRequest $pagination, Request $request): Response
    {
        $payload = $this->viewBuilder->accessRequestsPayload($pagination->page, $pagination->search);

        if ('XMLHttpRequest' === $request->headers->get('X-Requested-With')) {
            return $this->json($payload);
        }

        return $this->render('@Dev/suite/index.html.twig', $this->viewBuilder->indexView($payload, $pagination->search));
    }

    #[Route('/{id}/approve', name: '_approve', methods: [HttpMethodEnum::Post->value])]
    public function approve(AccessRequest $accessRequest): JsonResponse
    {
        if (!$accessRequest->isPending()) {
            return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value);
        }

        $generatedPassword = null;
        if (!$this->userManager->isEmailTaken($accessRequest->getRequesterEmail())) {
            $generatedPassword = bin2hex(random_bytes(8));
            $this->userManager->create(
                $accessRequest->getRequesterName() ?? 'Utilisateur',
                $accessRequest->getRequesterEmail(),
                $generatedPassword,
            );
        }

        $this->accessRequestManager->approve($accessRequest, $generatedPassword);

        return $this->jsonSuccess(['message' => $this->translator->trans('suite.access_requests.approved', [
            '{name}' => $accessRequest->getRequesterName() ?? $accessRequest->getRequesterEmail(),
        ])]);
    }

    #[Route('/{id}/reject', name: '_reject', methods: [HttpMethodEnum::Post->value])]
    public function reject(AccessRequest $accessRequest): JsonResponse
    {
        if (!$accessRequest->isPending()) {
            return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value);
        }

        $this->accessRequestManager->reject($accessRequest);

        return $this->jsonSuccess(['message' => $this->translator->trans('suite.access_requests.rejected', [
            '{name}' => $accessRequest->getRequesterName() ?? $accessRequest->getRequesterEmail(),
        ])]);
    }

    #[Route('/purge', name: '_purge', methods: [HttpMethodEnum::Post->value])]
    public function purge(): JsonResponse
    {
        $this->accessRequestRepository->deleteProcessed();

        return $this->jsonSuccess(['message' => $this->translator->trans('suite.access_requests.purged')]);
    }
}
