<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Controller\Dev;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Module\Toggle\ModuleToggleRegistry;
use Aurora\Module\Configuration\Setting\Enum\SettingErrorCodeEnum;
use Aurora\Module\Configuration\Setting\Exception\CascadeViolationException;
use Aurora\Module\Configuration\Setting\Service\SettingsService;
use Aurora\Module\Configuration\Setting\View\ModulesViewBuilder;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/dev/dashboard/modules', name: 'dev_modules')]
#[IsGranted(UserRoleEnum::Dev->value)]
final class ModulesController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ModulesViewBuilder $viewBuilder,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ModuleToggleRegistry $moduleToggleRegistry,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(Request $request): Response
    {
        $payload = $this->viewBuilder->modulesPayload();

        if ('XMLHttpRequest' === $request->headers->get('X-Requested-With')) {
            return $this->json($payload);
        }

        return $this->render('@Dev/suite/index.html.twig', $this->viewBuilder->indexView($payload));
    }

    #[Route('/verify-password', name: '_verify_password', methods: [HttpMethodEnum::Post->value])]
    public function verifyPassword(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof CoreUserInterface) {
            return $this->jsonForbidden();
        }

        $data = json_decode($request->getContent(), true);
        $password = isset($data['password']) ? (string) $data['password'] : '';

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            return $this->jsonFailure('invalid_password', HttpStatusEnum::Unauthorized->value);
        }

        return $this->jsonSuccess();
    }

    #[Route('/{key}', name: '_update', methods: [HttpMethodEnum::Patch->value])]
    public function update(string $key, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $value = isset($data['value']) ? (string) $data['value'] : null;

        // Accepts any registered toggle (core ModuleParameterEnum or
        // client-declared via ModuleToggleProviderInterface). Filtering by
        // the enum alone would reject `app_tracking_*` and other client keys.
        if (!$this->moduleToggleRegistry->has($key)) {
            return $this->jsonForbidden();
        }

        try {
            $this->settingsService->set($key, $value);
        } catch (CascadeViolationException $cascadeViolationException) {
            return $this->jsonFailure(
                SettingErrorCodeEnum::CascadeViolation->value,
                HttpStatusEnum::Conflict->value,
                ['parentKey' => $cascadeViolationException->parentKey],
            );
        }

        return $this->jsonSuccess(['key' => $key, 'value' => $value]);
    }
}
