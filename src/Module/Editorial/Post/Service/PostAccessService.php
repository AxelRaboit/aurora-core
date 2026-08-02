<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Who sees whose posts in the backend list.
 *
 * The voter guards a post once you have one; a list has none to guard, so
 * the scoping has to happen in the query. Without it an editor with only
 * `editorial.posts.view` would read every colleague's drafts.
 */
final readonly class PostAccessService
{
    public function __construct(private Security $security) {}

    /**
     * The author id list queries must be restricted to, or null when the
     * caller is allowed to see everything.
     */
    public function scopedAuthorId(): ?int
    {
        if ($this->security->isGranted(UserRoleEnum::Dev->value)
            || $this->security->isGranted(UserRoleEnum::Admin->value)) {
            return null;
        }

        $currentUser = $this->security->getUser();

        return $currentUser instanceof CoreUserInterface ? $currentUser->getId() : null;
    }
}
