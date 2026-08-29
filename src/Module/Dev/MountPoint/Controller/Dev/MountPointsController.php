<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\MountPoint\Controller\Dev;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Dev\MountPoint\Dto\MountPointInputFactoryInterface;
use Aurora\Module\Dev\MountPoint\Entity\MountPointInterface;
use Aurora\Module\Dev\MountPoint\Manager\MountPointManagerInterface;
use Aurora\Module\Dev\MountPoint\Serializer\MountPointSerializerInterface;
use Aurora\Module\Dev\MountPoint\Service\MountPointTesterService;
use Aurora\Module\Dev\MountPoint\View\MountPointsViewBuilder;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dev/dashboard/mount-points', name: 'dev_mount_points')]
#[IsGranted(UserRoleEnum::Dev->value)]
class MountPointsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly MountPointSerializerInterface $mountPointSerializer,
        private readonly MountPointsViewBuilder $viewBuilder,
        private readonly MountPointManagerInterface $mountPointManager,
        private readonly MountPointInputFactoryInterface $mountPointInputFactory,
        private readonly MountPointTesterService $testerService,
        private readonly PayloadValidator $payloadValidator,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Request $request): Response
    {
        $payload = $this->viewBuilder->listPayload();

        if ('XMLHttpRequest' === $request->headers->get('X-Requested-With')) {
            return $this->json($payload);
        }

        return $this->render('@Dev/backend/index.html.twig', $this->viewBuilder->indexView($payload));
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $input = $this->mountPointInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $mountPoint = $this->mountPointManager->create($input);

        return $this->jsonSuccess(['mountPoint' => $this->mountPointSerializer->serialize($mountPoint)]);
    }

    #[Route('/{id}', name: '_update', methods: [HttpMethodEnum::Patch->value])]
    public function update(MountPointInterface $mountPoint, Request $request): JsonResponse
    {
        $input = $this->mountPointInputFactory->fromArray($this->decodeJson($request));
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->mountPointManager->update($mountPoint, $input);

        return $this->jsonSuccess(['mountPoint' => $this->mountPointSerializer->serialize($mountPoint)]);
    }

    #[Route('/{id}/test', name: '_test', methods: [HttpMethodEnum::Post->value])]
    public function test(MountPointInterface $mountPoint): JsonResponse
    {
        $result = $this->testerService->test($mountPoint);

        $this->mountPointManager->recordTestResult($mountPoint, $result->success);

        return $this->jsonSuccess([
            'testSuccess' => $result->success,
            'testMessage' => $result->message,
            'mountPoint' => $this->mountPointSerializer->serialize($mountPoint),
        ]);
    }

    #[Route('/{id}', name: '_delete', methods: [HttpMethodEnum::Delete->value])]
    public function delete(MountPointInterface $mountPoint): JsonResponse
    {
        $this->mountPointManager->delete($mountPoint);

        return $this->jsonSuccess();
    }
}
