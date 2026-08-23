<?php

declare(strict_types=1);

namespace Aurora\Core\Twig;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Service\UserProfilePhotoUrlGenerator;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig bridge over the storage URL generators. Templates use:
 *
 *     {{ aurora_profile_photo_url(user) }}
 *
 * `user.profilePhotoUrl` no longer exists on entities - URL building was
 * moved into a dedicated service to keep the domain model free of HTTP
 * concerns. See CLAUDE.md §5bis and §3bis.
 *
 * `aurora_media_url` was retired during the Phase 2 Media → GED migration
 * - no template ever consumed it, and document URLs now flow through
 * serializers (DocumentSerializer exposes `fileUrl` directly).
 */
final readonly class StorageUrlExtension
{
    public function __construct(
        private UserProfilePhotoUrlGenerator $userProfilePhotoUrlGenerator,
    ) {}

    #[AsTwigFunction(name: 'aurora_profile_photo_url')]
    public function profilePhotoUrl(?CoreUserInterface $user): ?string
    {
        return $this->userProfilePhotoUrlGenerator->url($user);
    }
}
