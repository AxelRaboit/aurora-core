<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Preview\Manager;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Preview\Entity\PostPreviewToken;
use Aurora\Module\Editorial\Post\Preview\Entity\PostPreviewTokenInterface;
use Aurora\Module\Editorial\Post\Preview\Repository\PostPreviewTokenRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mints preview addresses, and hands back the live one rather than a second.
 */
class PostPreviewTokenManager implements PostPreviewTokenManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly PostPreviewTokenRepository $tokens,
    ) {}

    public function resolveOrCreate(
        PostInterface $post,
        ?CoreUserInterface $author = null,
        ?DateTimeImmutable $now = null,
    ): PostPreviewTokenInterface {
        $now ??= new DateTimeImmutable();

        $existing = $this->tokens->findLiveFor($post, $now);

        if ($existing instanceof PostPreviewTokenInterface) {
            return $existing;
        }

        $token = $this->createToken();
        $token->setPost($post);
        $token->setCreatedBy($author);

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    /**
     * Ends a preview now.
     *
     * Deleted rather than stamped revoked, unlike a calendar share link. That one
     * keeps the row because "when did we close it" is asked after a leak of a thing
     * shared on purpose; a preview is a glance nobody audits, and a table of dead
     * glances is clutter.
     */
    public function revoke(PostInterface $post, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $existing = $this->tokens->findLiveFor($post, $now);

        if (!$existing instanceof PostPreviewTokenInterface) {
            return;
        }

        $this->entityManager->remove($existing);
        $this->entityManager->flush();
    }

    /**
     * The token behind a value, if it still works.
     *
     * Null for unknown and expired alike: the caller renders the same page for
     * both, so telling them apart here would only invite telling them apart there.
     */
    public function resolveUsable(string $token, ?DateTimeImmutable $now = null): ?PostPreviewTokenInterface
    {
        $now ??= new DateTimeImmutable();
        $found = $this->tokens->findByToken($token);

        if (!$found instanceof PostPreviewTokenInterface || !$found->isUsableAt($now)) {
            return null;
        }

        return $found;
    }

    protected function createToken(): PostPreviewTokenInterface
    {
        return new PostPreviewToken();
    }
}
