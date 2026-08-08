<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Manager;

use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Entity\CommentReaction;
use Aurora\Module\Editorial\Comment\Entity\CommentReactionInterface;
use Aurora\Module\Editorial\Comment\Enum\ReactionTypeEnum;
use Aurora\Module\Editorial\Comment\Repository\CommentReactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

#[AsAlias(CommentReactionManagerInterface::class)]
class CommentReactionManager implements CommentReactionManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly CommentReactionRepository $reactionRepository,
        #[Autowire(param: 'kernel.secret')]
        protected readonly string $secret,
    ) {}

    public function toggle(CommentInterface $comment, ReactionTypeEnum $type, string $fingerprint): array
    {
        $existing = $this->reactionRepository->findOneForReader($comment, $fingerprint);

        if ($existing instanceof CommentReactionInterface) {
            // Same emoji again means "take it back"; a different one means
            // "I meant this instead". One reaction per reader per comment,
            // which is what the unique index enforces anyway.
            if ($existing->getType() === $type) {
                $this->entityManager->remove($existing);
            } else {
                $existing->setType($type);
            }
        } else {
            $reaction = $this->createCommentReaction();
            $reaction->setComment($comment);
            $reaction->setType($type);
            $reaction->setFingerprint($fingerprint);

            $this->entityManager->persist($reaction);
        }

        $this->entityManager->flush();

        return $this->reactionRepository->countByComment($comment);
    }

    /**
     * Stands in for a reader who is not signed in.
     *
     * Salted with the application secret. An unsalted sha256 of an address
     * and a user agent is not anonymous — the space is small enough to walk,
     * so anyone with the table and a suspected visitor could confirm what
     * they reacted to. The salt makes the stored value useless outside this
     * installation, which is all it ever needs to be.
     */
    public function fingerprint(Request $request): string
    {
        return hash_hmac(
            'sha256',
            $request->getClientIp().'|'.$request->headers->get('User-Agent', ''),
            $this->secret,
        );
    }

    protected function createCommentReaction(): CommentReactionInterface
    {
        return new CommentReaction();
    }
}
