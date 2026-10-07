<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Security\DriveLock;
use Aurora\Module\Studio\CustomerSpace\Security\SpaceVisibility;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveFolderId;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function mb_trim;
use function sprintf;

/**
 * A space's settings, open to the lead.
 *
 * **This is what finally gives the lead role a meaning.** Until now it only
 * said who to talk to; it now opens a door their teammates do not have. The
 * rest of the work does not move: a teammate writes, comments and schedules
 * as before.
 *
 * **A 404 and not a 403** for whoever has no right to it, as everywhere else
 * on a space: not confirming that a door exists says less than refusing it
 * politely.
 */
#[Route('/workspace/{id}/settings', name: 'workspace_space_settings', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
final class SpaceSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly SpaceVisibility $visibility,
        private readonly DriveLock $lock,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactoryInterface $spaceDriveUnlockLimiter,
    ) {}

    /** The state of the settings, without ever returning the password itself. */
    #[Route('', name: '_show', methods: [HttpMethodEnum::Get->value])]
    public function show(CustomerSpace $space): JsonResponse
    {
        $this->denyUnlessReferent($space);

        return $this->jsonSuccess(['settings' => $this->state($space)]);
    }

    /**
     * The Drive folder this space looks at.
     *
     * **Here and not on the Drive tab**, where it first was. Pointing at a
     * customer's folder is configuration: it is decided once, by whoever
     * answers for the space. Left above the file list, the field could be
     * edited by anyone who opened the tab, and it took room on a screen people
     * come to consult.
     *
     * The whole address is accepted and split: it is what is at hand when
     * coming out of Drive, and requiring the bare id would make the most
     * natural gesture fail.
     */
    #[Route('/drive-folder', name: '_drive_folder', methods: [HttpMethodEnum::Post->value])]
    public function setDriveFolder(CustomerSpace $space, Request $request): JsonResponse
    {
        $this->denyUnlessReferent($space);

        $given = mb_trim((string) ($this->decodeJson($request)['folder'] ?? ''));

        if ('' === $given) {
            $space->setDriveFolderId(null);
            $this->entityManager->flush();

            return $this->jsonSuccess(['settings' => $this->state($space)]);
        }

        $folderId = DriveFolderId::from($given);

        if (null === $folderId) {
            return $this->jsonFailure('suite.studio.drive.errors.folder_invalid');
        }

        $space->setDriveFolderId($folderId);
        $this->entityManager->flush();

        return $this->jsonSuccess(['settings' => $this->state($space)]);
    }

    /**
     * Sets or replaces the Drive password.
     *
     * Replacing asks for the old one: without that, a screen left open would
     * be enough to change it, and the lock would only hold until the next
     * coffee break.
     */
    #[Route('/drive-password', name: '_drive_password', methods: [HttpMethodEnum::Post->value])]
    public function setDrivePassword(CustomerSpace $space, Request $request): JsonResponse
    {
        $this->denyUnlessReferent($space);

        $payload = $this->decodeJson($request);
        $password = (string) ($payload['password'] ?? '');
        $current = (string) ($payload['currentPassword'] ?? '');

        if ($space->isDriveLocked() && !$this->lock->matches($space, $current)) {
            return $this->jsonFailure('suite.studio.spaces.settings.errors.wrong_password');
        }

        if (!$this->lock->isAcceptable($password)) {
            return $this->jsonFailure('suite.studio.spaces.settings.errors.password_too_short');
        }

        $this->lock->set($space, $password);
        $this->entityManager->flush();

        return $this->jsonSuccess(['settings' => $this->state($space)]);
    }

    /**
     * Reopens the tab, against the current password.
     *
     * **Entering it is what tells a lock from a speed bump.** Without that
     * requirement, anyone reaching this screen removes it in one click. The
     * price is that forgetting it blocks: the fallback is
     * `aurora:space:drive-password:clear` on the server, and that is the right
     * level of authority to force a door.
     */
    #[Route('/drive-password/clear', name: '_drive_password_clear', methods: [HttpMethodEnum::Post->value])]
    public function clearDrivePassword(CustomerSpace $space, Request $request): JsonResponse
    {
        $this->denyUnlessReferent($space);

        if (!$space->isDriveLocked()) {
            return $this->jsonSuccess(['settings' => $this->state($space)]);
        }

        $current = mb_trim((string) ($this->decodeJson($request)['currentPassword'] ?? ''));

        if (!$this->lock->matches($space, $current)) {
            return $this->jsonFailure('suite.studio.spaces.settings.errors.wrong_password');
        }

        $this->lock->clear($space);
        $this->entityManager->flush();

        return $this->jsonSuccess(['settings' => $this->state($space)]);
    }

    /**
     * Asks everyone for the password again.
     *
     * **Without changing the password**, which is the whole point: those who
     * know it type it again, and nobody has to be given a new one. It is the
     * answer to the ordinary doubt, a screen left open somewhere else, rather
     * than to a leaked password, which calls for changing it.
     *
     * Nothing to enter to press it: the gesture grants access to nothing, it
     * takes some away. Refusing a button that only locks again would be harsh
     * for nothing.
     */
    #[Route('/drive-revoke', name: '_drive_revoke', methods: [HttpMethodEnum::Post->value])]
    public function revokeDriveSessions(CustomerSpace $space): JsonResponse
    {
        $this->denyUnlessReferent($space);

        if (!$space->isDriveLocked()) {
            return $this->jsonFailure('suite.studio.spaces.settings.errors.not_locked');
        }

        $this->lock->revoke($space);
        $this->entityManager->flush();

        return $this->jsonSuccess(['settings' => $this->state($space)]);
    }

    /**
     * Opens the Drive tab for this session.
     *
     * On the settings and not on the Drive, because this is where a space
     * password is understood; the Drive screen only shows the prompt.
     */
    #[Route('/drive-unlock', name: '_drive_unlock', methods: [HttpMethodEnum::Post->value])]
    public function unlockDrive(CustomerSpace $space, Request $request): JsonResponse
    {
        // Not `denyUnlessReferent`: unlocking is not configuring. Any teammate
        // who sees the space can open the tab if they know the password,
        // which is exactly what a password means.
        if (!$this->visibility->canSee($space)) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        $attempts = $this->spaceDriveUnlockLimiter->create(sprintf('%s-%d', $user?->getUserIdentifier() ?? 'anonymous', (int) $space->getId()));

        if (!$attempts->consume()->isAccepted()) {
            return $this->jsonFailure('suite.studio.spaces.settings.errors.too_many_attempts', HttpStatusEnum::TooManyRequests->value);
        }

        $password = (string) ($this->decodeJson($request)['password'] ?? '');

        if (!$this->lock->unlock($space, $password)) {
            return $this->jsonFailure('suite.studio.spaces.settings.errors.wrong_password');
        }

        // The first opening of a space locked before generations existed
        // gives it one: without this save, the session would hold a value the
        // space does not carry.
        $this->entityManager->flush();

        return $this->jsonSuccess(['unlocked' => true]);
    }

    /** @return array<string, mixed> */
    private function state(CustomerSpace $space): array
    {
        return [
            'driveFolderId' => $space->getDriveFolderId(),
            'driveLocked' => $space->isDriveLocked(),
            'driveUnlocked' => $this->lock->isUnlocked($space),
            'minPasswordLength' => DriveLock::MIN_LENGTH,
        ];
    }

    private function denyUnlessReferent(CustomerSpace $space): void
    {
        if (!$this->visibility->canConfigure($space)) {
            throw $this->createNotFoundException();
        }
    }
}
