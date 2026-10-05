<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Controller\Dev;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Platform\User\Dto\CreateUserInput;
use Aurora\Module\Platform\User\Dto\UpdateUserInput;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Module\Platform\User\View\DevUsersViewBuilder;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/dev/dashboard/users', name: 'dev_users')]
#[IsGranted(UserRoleEnum::Dev->value)]
final class UsersController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly UserManagerInterface $userManager,
        private readonly PayloadValidator $payloadValidator,
        private readonly TranslatorInterface $translator,
        private readonly DevUsersViewBuilder $viewBuilder,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(PaginationRequest $pagination, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $payload = $this->viewBuilder->usersPayload($pagination->page, $pagination->search, $currentUser);

        if ('XMLHttpRequest' === $request->headers->get('X-Requested-With')) {
            return $this->jsonSuccess($payload);
        }

        return $this->render('@Dev/suite/index.html.twig', $this->viewBuilder->indexView($payload, $pagination->search));
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $input = CreateUserInput::fromArray($data);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $user = $this->userManager->create($input->name, $input->email, $input->password);
        $this->userManager->changeLocaleEnum($user, $input->locale);
        $this->addFlash('success', $this->translator->trans('suite.users.toast.created'));

        return $this->jsonSuccess(['id' => $user->getId()]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(User $user, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $input = UpdateUserInput::fromArray($data);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->userManager->update($user, $input->name, $input->email);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['email' => $invalidArgumentException->getMessage()]);
        }

        $this->userManager->changeLocaleEnum($user, $input->locale);

        if ('' !== $input->password) {
            $this->userManager->changePassword($user, $input->password);
        }

        $this->addFlash('success', $this->translator->trans('suite.users.toast.updated'));

        return $this->jsonSuccess(['id' => $user->getId()]);
    }

    #[Route('/{id}/toggle-role', name: '_toggle_role', methods: [HttpMethodEnum::Post->value])]
    public function toggleRole(User $user): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($user->getId() === $currentUser->getId()) {
            $this->addFlash('error', $this->translator->trans('suite.users.cannot_modify_self'));

            return $this->redirectToRoute('dev_users');
        }

        $isDev = $this->userManager->toggleDevRole($user);
        $this->addFlash(
            'success',
            $this->translator->trans($isDev ? 'suite.users.dev_granted' : 'suite.users.dev_revoked'),
        );

        return $this->redirectToRoute('dev_users');
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(User $user): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($user->getId() === $currentUser->getId()) {
            $this->addFlash('error', $this->translator->trans('suite.users.cannot_delete_self'));

            return $this->redirectToRoute('dev_users');
        }

        $this->userManager->delete($user);
        $this->addFlash('success', $this->translator->trans('suite.users.toast.deleted'));

        return $this->redirectToRoute('dev_users');
    }
}
