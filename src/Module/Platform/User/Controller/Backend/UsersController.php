<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Platform\User\Dto\UserInputFactoryInterface;
use Aurora\Module\Platform\User\Dto\UserInviteInputFactoryInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Manager\UserHierarchyManagerInterface;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Module\Platform\User\Manager\UserProfilePhotoManagerInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Platform\User\Serializer\UserSerializerInterface;
use Aurora\Module\Platform\User\View\UsersViewBuilder;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/platform/users', name: 'backend_platform_users')]
#[IsGranted('platform.users.manage')]
class UsersController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly UserRepository $userRepository,
        protected readonly UserManagerInterface $userManager,
        protected readonly UserHierarchyManagerInterface $userHierarchyManager,
        protected readonly UserSerializerInterface $userSerializer,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly UserProfilePhotoManagerInterface $userProfilePhotoManager,
        protected readonly UsersViewBuilder $viewBuilder,
        protected readonly UserInputFactoryInterface $userInputFactory,
        protected readonly UserInviteInputFactoryInterface $userInviteInputFactory,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        $currentUser = $this->getUser();

        return $this->render('@Platform/backend/users/index.html.twig', $this->viewBuilder->indexView(
            $this->isGranted(UserRoleEnum::Dev->value),
            $currentUser instanceof User ? $currentUser : null,
            $this->isGranted('platform.users.module_access.manage'),
        ));
    }

    #[Route('/list', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(PaginationRequest $pagination, Request $request): JsonResponse
    {
        $role = mb_trim((string) $request->query->get('role', ''));

        $result = $this->userRepository->findPaginated($pagination->page, 10, $pagination->search, $role ?: null);

        return $this->jsonSuccess([
            'items' => array_map($this->userSerializer->serialize(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    #[Route('/selectable', name: '_selectable', methods: [HttpMethodEnum::Get->value])]
    public function selectable(): JsonResponse
    {
        $items = array_map(
            static fn (CoreUserInterface $user): array => [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
            ],
            $this->userRepository->findAllAdminsAlphabetical(),
        );

        return $this->jsonSuccess(['items' => $items]);
    }

    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function show(User $user): JsonResponse
    {
        return $this->jsonSuccess(['user' => $this->userSerializer->serializeWithSubordinates($user)]);
    }

    #[Route('/invite', name: '_invite', methods: [HttpMethodEnum::Post->value])]
    public function invite(Request $request): JsonResponse
    {
        $input = $this->userInviteInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $user = $this->userManager->invite($input->getName(), $input->getEmail(), $input->getRole(), $input->getMessage());
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['role' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['user' => $this->userSerializer->serialize($user)]);
    }

    #[Route('/{id}/edit', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(User $user, Request $request): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User || !$this->userManager->canActOn($currentUser, $user)) {
            return $this->jsonForbidden();
        }

        $input = $this->userInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->userHierarchyManager->applyManager($user, $input->getManagerId());
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['managerId' => $invalidArgumentException->getMessage()]);
        }

        try {
            $this->userManager->updateWithRole($user, $input->getName(), $input->getEmail(), $input->getRole(), $input->getPassword());
        } catch (InvalidArgumentException $invalidArgumentException) {
            $field = str_contains($invalidArgumentException->getMessage(), 'email') ? 'email' : 'role';

            return $this->jsonInvalidInput([$field => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['user' => $this->userSerializer->serialize($user)]);
    }

    #[Route('/{id}/resend-invitation', name: '_resend_invitation', methods: [HttpMethodEnum::Post->value])]
    public function resendInvitation(User $user): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User || !$this->userManager->canActOn($currentUser, $user)) {
            return $this->jsonForbidden();
        }

        $this->userManager->resendInvitation($user, null);

        return $this->jsonSuccess(['user' => $this->userSerializer->serialize($user)]);
    }

    #[Route('/{id}/toggle-disabled', name: '_toggle_disabled', methods: [HttpMethodEnum::Post->value])]
    public function toggleDisabled(User $user): JsonResponse
    {
        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            return $this->jsonFailure('backend.users.cannot_disable_self');
        }

        if (!$currentUser instanceof User || !$this->userManager->canActOn($currentUser, $user)) {
            return $this->jsonForbidden();
        }

        $this->userManager->toggleDisabled($user);

        return $this->jsonSuccess(['user' => $this->userSerializer->serialize($user)]);
    }

    #[Route('/{id}/photo', name: '_photo_upload', methods: [HttpMethodEnum::Post->value])]
    public function uploadPhoto(User $user, Request $request): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User || !$this->userManager->canActOn($currentUser, $user)) {
            return $this->jsonForbidden();
        }

        $file = $request->files->get('photo');
        if (null === $file) {
            return $this->jsonInvalidInput(['photo' => 'backend.users.photo.errors.missing']);
        }

        try {
            $this->userProfilePhotoManager->upload($user, $file);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['photo' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['user' => $this->userSerializer->serialize($user)]);
    }

    #[Route('/{id}/photo/delete', name: '_photo_delete', methods: [HttpMethodEnum::Post->value])]
    public function deletePhoto(User $user): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User || !$this->userManager->canActOn($currentUser, $user)) {
            return $this->jsonForbidden();
        }

        $this->userProfilePhotoManager->delete($user);

        return $this->jsonSuccess(['user' => $this->userSerializer->serialize($user)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(User $user): JsonResponse
    {
        $currentUser = $this->getUser();
        if ($currentUser instanceof User && $currentUser->getId() === $user->getId()) {
            return $this->jsonFailure('backend.users.cannot_delete_self');
        }

        if (!$currentUser instanceof User || !$this->userManager->canActOn($currentUser, $user)) {
            return $this->jsonForbidden();
        }

        $this->userManager->delete($user);

        return $this->jsonSuccess();
    }
}
