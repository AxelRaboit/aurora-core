<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Security;

use Aurora\Module\Editorial\Post\Entity\AbstractPost;
use Aurora\Module\Editorial\Post\Security\PostVoter;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * supports() matched the concrete Post. A client substituting the entity
 * therefore fell straight through it and got ABSTAIN - which, with nothing
 * else voting, reads as denied. An author would have been locked out of
 * their own posts with no error worth acting on.
 */
final class PostVoterTest extends TestCase
{
    public function testVotesOnAnySubstitutedPostEntity(): void
    {
        $post = $this->clientPost(authorId: 7);
        $voter = new PostVoter($this->accessDecisionManager(isAdmin: false));

        $vote = $voter->vote($this->token($this->user(7, ['editorial.posts.manage'])), $post, [PostVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $vote, 'A substituted entity must still be voted on.');
    }

    public function testAnAdminMayDoAnything(): void
    {
        $voter = new PostVoter($this->accessDecisionManager(isAdmin: true));

        foreach ([PostVoter::VIEW, PostVoter::EDIT, PostVoter::DELETE, PostVoter::PUBLISH] as $attribute) {
            self::assertSame(
                VoterInterface::ACCESS_GRANTED,
                $voter->vote($this->token($this->user(1, [])), $this->clientPost(authorId: 999), [$attribute]),
            );
        }
    }

    public function testAnAuthorEditsTheirOwnButDoesNotPublishIt(): void
    {
        $voter = new PostVoter($this->accessDecisionManager(isAdmin: false));
        $token = $this->token($this->user(7, ['editorial.posts.manage']));
        $own = $this->clientPost(authorId: 7);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $own, [PostVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $own, [PostVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $own, [PostVoter::PUBLISH]));
    }

    public function testAnAuthorDoesNotEditSomeoneElsesPost(): void
    {
        $voter = new PostVoter($this->accessDecisionManager(isAdmin: false));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($this->user(7, ['editorial.posts.manage'])), $this->clientPost(authorId: 8), [PostVoter::EDIT]),
        );
    }

    public function testViewOnlyPrivilegeReadsButDoesNotEdit(): void
    {
        $voter = new PostVoter($this->accessDecisionManager(isAdmin: false));
        $token = $this->token($this->user(7, ['editorial.posts.view']));
        $post = $this->clientPost(authorId: 8);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $post, [PostVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $post, [PostVoter::EDIT]));
    }

    /** A client's own Post: extends the abstract, not core's concrete class. */
    private function clientPost(int $authorId): AbstractPost
    {
        $post = new class extends AbstractPost {
            public function getId(): ?int
            {
                return 1;
            }
        };

        return $post->setAuthor($this->user($authorId, []));
    }

    /** @param list<string> $privileges */
    private function user(int $id, array $privileges): CoreUserInterface
    {
        $user = $this->createStub(CoreUserInterface::class);
        $user->method('getId')->willReturn($id);
        $user->method('hasPrivilege')->willReturnCallback(
            static fn (string $privilege): bool => in_array($privilege, $privileges, true),
        );

        return $user;
    }

    private function token(CoreUserInterface $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function accessDecisionManager(bool $isAdmin): AccessDecisionManagerInterface
    {
        $manager = $this->createStub(AccessDecisionManagerInterface::class);
        $manager->method('decide')->willReturn($isAdmin);

        return $manager;
    }
}
