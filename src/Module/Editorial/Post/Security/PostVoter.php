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

    /**
     * May change this post's gallery, and nothing else about it.
     *
     * Separate from EDIT because it is granted separately: a contributor holding
     * only `editorial.posts.gallery` gets this and not EDIT, and the narrow
     * endpoint is what makes "and nothing else" true rather than polite.
     */
    public const string GALLERY_EDIT = 'POST_GALLERY_EDIT';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::PUBLISH, self::GALLERY_EDIT], true)) {
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

        // The gallery privilege on its own, which is the whole point of it: a
        // photographer files pictures on any publication and can do nothing else
        // to it. Not scoped to authorship - somebody brought in for the pictures
        // did not write the article.
        if (self::GALLERY_EDIT === $attribute) {
            return $user->hasPrivilege('editorial.posts.gallery');
        }

        // Publishing is its own grant, so "reviewer" is a job somebody can be given
        // rather than a side effect of being an administrator. Before this, the
        // only people who could approve anything were Dev and Admin - which made
        // `pending_review` a status nobody was appointed to clear.
        if (self::PUBLISH === $attribute) {
            return $user->hasPrivilege('editorial.posts.publish');
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
