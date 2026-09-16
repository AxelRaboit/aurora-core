<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Service;

use Aurora\Module\Platform\User\Access\ProfilePhotoUploadAccessGuard;
use Aurora\Module\Platform\User\Controller\Backend\ProfilePhotoFilesController;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The entity holds the filename; this turns it into an address.
 *
 * **Under `/backend`, not `/uploads`.** `profile-photos/` is refused to the
 * public by {@see ProfilePhotoUploadAccessGuard},
 * so the catch-all no longer answers for it - a photo is read through
 * {@see ProfilePhotoFilesController},
 * which is under the admin firewall and can therefore ask who is looking.
 *
 * One consequence worth stating: an avatar cannot be rendered on a public
 * page any more. Nothing does today - the layout header and the profile screen
 * are the only two consumers, both in the back office - and a future public
 * surface that wants one needs its own answer rather than this one silently
 * being open.
 *
 * Returns `null` when the user has no photo set. See CLAUDE.md §5bis.
 */
final readonly class UserProfilePhotoUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function url(?CoreUserInterface $user): ?string
    {
        if (!$user instanceof CoreUserInterface) {
            return null;
        }

        $path = $user->getProfilePhotoPath();
        if (null === $path || '' === $path) {
            return null;
        }

        return $this->urlGenerator->generate('backend_platform_profile_photos_serve', ['filename' => $path]);
    }
}
