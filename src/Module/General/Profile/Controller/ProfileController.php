<?php

declare(strict_types=1);

namespace Aurora\Module\General\Profile\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Module\Service\ModuleRegistry;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\General\Profile\View\ProfileViewBuilder;
use Aurora\Module\Platform\Auth\Dto\ChangePasswordInput;
use Aurora\Module\Platform\User\Dto\MoodInput;
use Aurora\Module\Platform\User\Dto\UpdateProfileInput;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Aurora\Module\Platform\User\Manager\UserProfilePhotoManagerInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Platform\User\Service\UserProfilePhotoUrlGenerator;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;

#[Route('/backend/general/profile', name: 'backend_general_profile')]
#[IsGranted(UserRoleEnum::User->value)]
final class ProfileController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly UserManagerInterface $userManager,
        private readonly PayloadValidator $payloadValidator,
        private readonly TranslatorInterface $translator,
        private readonly UserProfilePhotoManagerInterface $userProfilePhotoManager,
        private readonly ProfileViewBuilder $viewBuilder,
        private readonly UserRepository $userRepository,
        private readonly ModuleRegistry $moduleRegistry,
        private readonly UserProfilePhotoUrlGenerator $userProfilePhotoUrlGenerator,
    ) {}

    #[Route('', name: '')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('@General/backend/profile/index.html.twig', $this->viewBuilder->indexView($user));
    }

    #[Route('/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $input = UpdateProfileInput::fromArray($data);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->userManager->update($user, $input->name, $input->email);

        return $this->jsonSuccess();
    }

    #[Route('/password', name: '_password', methods: [HttpMethodEnum::Post->value])]
    public function password(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $input = ChangePasswordInput::fromArray($data);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        if (!$this->userManager->isPasswordValid($user, $input->currentPassword)) {
            return $this->jsonInvalidInput([
                'current_password' => $this->translator->trans('backend.profile.errors.current_password_invalid'),
            ]);
        }

        $this->userManager->changePassword($user, $input->password);

        return $this->jsonSuccess();
    }

    #[Route('/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(Request $request, TokenStorageInterface $tokenStorage): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];

        if (!$this->isCsrfTokenValid('profile_delete', $data['_token'] ?? '')) {
            return $this->jsonFailure($this->translator->trans('backend.profile.errors.invalid_csrf'), HttpStatusEnum::Forbidden->value);
        }

        if ($this->isLastDevOfType($user)) {
            return $this->jsonFailure($this->translator->trans('backend.profile.errors.last_dev_protected'), HttpStatusEnum::Forbidden->value);
        }

        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();
        $this->userManager->delete($user);

        return $this->jsonSuccess();
    }

    /**
     * Protects the seed/last developer account: an instance must always retain
     * at least one ROLE_DEV user of the same scope (Backend or Frontend).
     * Without this guard, deleting the only dev would lock the app out of any
     * dev-only operation (impersonation, advanced settings, etc.).
     */
    private function isLastDevOfType(User $user): bool
    {
        if (!in_array(UserRoleEnum::Dev->value, $user->getRoles(), true)) {
            return false;
        }

        return 1 === $this->userRepository->countByRoleAndType(UserRoleEnum::Dev->value, $user->getType());
    }

    #[Route('/mood', name: '_mood', methods: [HttpMethodEnum::Post->value])]
    public function mood(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $input = MoodInput::fromRequest($request);
        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->userManager->changeMoodMessage($user, $input->moodMessage);

        return $this->jsonSuccess(['moodMessage' => $user->getMoodMessage()]);
    }

    #[Route('/photo', name: '_photo_upload', methods: [HttpMethodEnum::Post->value])]
    public function uploadPhoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $file = $request->files->get('photo');
        if (null === $file) {
            return $this->jsonInvalidInput(['photo' => 'backend.users.photo.errors.missing']);
        }

        try {
            $this->userProfilePhotoManager->upload($user, $file);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['photo' => $invalidArgumentException->getMessage()]);
        }

        return $this->jsonSuccess(['profilePhotoUrl' => $this->userProfilePhotoUrlGenerator->url($user)]);
    }

    #[Route('/photo/delete', name: '_photo_delete', methods: [HttpMethodEnum::Post->value])]
    public function deletePhoto(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->userProfilePhotoManager->delete($user);

        return $this->jsonSuccess(['profilePhotoUrl' => null]);
    }

    #[Route('/sidemenu', name: '_sidemenu', methods: [HttpMethodEnum::Get->value])]
    public function sidemenu(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('@General/backend/profile/sidemenu.html.twig', [
            'navPreferences' => $this->moduleRegistry->getNavPreferences(),
            'hiddenNavSections' => $user->getHiddenNavSections(),
            'hiddenNavItems' => $user->getHiddenNavItems(),
            'navSectionColors' => $user->getNavSectionColors(),
        ]);
    }

    #[Route('/sidemenu', name: '_sidemenu_save', methods: [HttpMethodEnum::Post->value])]
    public function sidemenuSave(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = $this->decodeJson($request);

        $hiddenSections = $data['hiddenNavSections'] ?? [];
        $hiddenItems = $data['hiddenNavItems'] ?? [];
        $sectionColors = $data['navSectionColors'] ?? [];

        if (!is_array($hiddenSections) || !is_array($hiddenItems) || !is_array($sectionColors)) {
            return $this->jsonInvalidInput([
                'hiddenNavSections' => 'Invalid format',
                'hiddenNavItems' => 'Invalid format',
                'navSectionColors' => 'Invalid format',
            ]);
        }

        $cleanColors = [];
        foreach ($sectionColors as $sectionId => $colorName) {
            if (is_string($sectionId) && is_string($colorName)) {
                $cleanColors[$sectionId] = $colorName;
            }
        }

        $this->userManager->updateSidemenuPreferences(
            $user,
            array_values(array_filter($hiddenSections, is_string(...))),
            array_values(array_filter($hiddenItems, is_string(...))),
            $cleanColors,
        );

        return $this->jsonSuccess([
            'hiddenNavSections' => $user->getHiddenNavSections(),
            'hiddenNavItems' => $user->getHiddenNavItems(),
            'navSectionColors' => $user->getNavSectionColors(),
        ]);
    }

    /**
     * Collapsing is its own call rather than part of the sidemenu form: it
     * happens on a button in the menu itself, nowhere near the preferences
     * screen, and sending the whole customisation to toggle one flag would
     * make a click that should be instant depend on state the caller does not
     * have.
     */
    #[Route('/sidemenu/collapsed', name: '_sidemenu_collapsed', methods: [HttpMethodEnum::Post->value])]
    public function sidemenuCollapsed(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->userManager->updateSidemenuCollapsed($user, (bool) ($this->decodeJson($request)['collapsed'] ?? false));

        return $this->jsonSuccess(['collapsed' => $user->isSidemenuCollapsed()]);
    }

    /**
     * Its own call too, and for the same reason: the switch sits at the top of
     * the menu, and turning it on should not have to know the rest of the
     * user's customisation to send it back unchanged.
     */
    #[Route('/sidemenu/descriptions', name: '_sidemenu_descriptions', methods: [HttpMethodEnum::Post->value])]
    public function sidemenuShowDescriptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->userManager->updateSidemenuShowDescriptions($user, (bool) ($this->decodeJson($request)['show'] ?? false));

        return $this->jsonSuccess(['show' => $user->isSidemenuShowDescriptions()]);
    }

    #[Route('/sidemenu/reset', name: '_sidemenu_reset', methods: [HttpMethodEnum::Post->value])]
    public function sidemenuReset(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->userManager->resetSidemenuPreferences($user);

        return $this->jsonSuccess();
    }

    #[Route('/locale', name: '_locale', methods: [HttpMethodEnum::Post->value])]
    public function locale(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $locale = LocaleEnum::tryFrom($data['locale'] ?? '');

        if (null === $locale) {
            return $this->jsonFailure($this->translator->trans('backend.profile.errors.invalid_locale'));
        }

        $request->getSession()->set('_locale', $locale->value);
        $this->userManager->changeLocaleEnum($user, $locale);

        return $this->jsonSuccess();
    }
}
