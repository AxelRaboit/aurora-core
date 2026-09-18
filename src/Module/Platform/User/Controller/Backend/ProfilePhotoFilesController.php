<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\Access\UploadAccessDecider;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\StoredFileName;
use Aurora\Core\Storage\StoredFileResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function sprintf;

/**
 * Where a profile photo is read, now that `profile-photos/` refuses the
 * public.
 *
 * The area used to have no guard at all, which under
 * {@see UploadAccessDecider}'s documented default
 * meant anonymous: anybody holding the address read the photo. That was only
 * ever defensible because the address looked random, and it was not - the name
 * was `<account id>-<uniqid>.jpg`, an integer that counts up followed by the
 * microsecond of the upload.
 *
 * Both halves are fixed rather than one: the name is random now
 * ({@see StoredFileName}), and this route means the name
 * is no longer the lock.
 *
 * **Why a route under `/backend` and not a check on the catch-all.** The admin
 * firewall is a path pattern, so a request to `/uploads/…` is handled by the
 * front firewall with no backend identity to test: a privilege check placed
 * there would refuse staff exactly as it refuses strangers. Being under
 * `/backend` is what makes the question answerable at all - the arrangement
 * CLAUDE.md §5bis prescribes, and the one GED and contracts already use.
 *
 * **`ROLE_USER` and not a module privilege.** Everybody who can open the back
 * office sees their own avatar in the layout, including an account holding no
 * privilege at all; gating this behind `platform.users.view` would blank the
 * header for exactly the people it is meant to greet.
 *
 * A flat filename, not a path: the area has no folders, so the route pattern
 * refuses a separator outright rather than guarding against traversal later.
 */
#[Route('/backend/platform/profile-photos', name: 'backend_platform_profile_photos')]
#[IsGranted('ROLE_USER')]
final class ProfilePhotoFilesController extends AbstractController
{
    public function __construct(
        private readonly StoredFileResponder $responder,
    ) {}

    #[Route(
        '/{filename}',
        name: '_serve',
        requirements: ['filename' => '[A-Za-z0-9._-]+'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function serve(string $filename): Response
    {
        $key = sprintf('%s/%s', StorageAreaEnum::ProfilePhotos->value, $filename);

        // Servi par le service commun : local déchargé par le serveur
        // web, distant diffusé par morceaux, privé une heure.
        return $this->responder->respond($key);
    }
}
