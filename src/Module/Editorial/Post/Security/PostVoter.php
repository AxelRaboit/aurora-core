<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Security;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Subjects are typed on PostInterface, not the concrete Post: a client
 * substituting the entity would otherwise fall through supports() and get
 * no vote at all - which reads as "denied" and silently locks them out of
 * their own posts.
 */
final class PostVoter extends Voter
{
    public const string VIEW = 'POST_VIEW';

    public const string EDIT = 'POST_EDIT';

    public const string DELETE = 'POST_DELETE';

    public const string PUBLISH = 'POST_PUBLISH';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::PUBLISH], true)) {
            return false;
        }

        return $subject instanceof PostInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof CoreUserInterface || !$subject instanceof PostInterface) {
            return false;
        }

        if ($this->accessDecisionManager->decide($token, [UserRoleEnum::Dev->value])
            || $this->accessDecisionManager->decide($token, [UserRoleEnum::Admin->value])) {
            return true;
        }

        // An author manages their own posts, but publishing stays a decision
        // someone else makes.
        if ($user->hasPrivilege('editorial.posts.manage')) {
            $author = $subject->getAuthor();
            $isOwner = $author instanceof CoreUserInterface && $author->getId() === $user->getId();

            return $isOwner && in_array($attribute, [self::VIEW, self::EDIT], true);
        }

        return self::VIEW === $attribute && $user->hasPrivilege('editorial.posts.view');
    }
}
