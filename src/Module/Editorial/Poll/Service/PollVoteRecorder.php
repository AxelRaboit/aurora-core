<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Poll\Service;

use Aurora\Module\Editorial\Poll\Entity\PollVote;
use Aurora\Module\Editorial\Poll\Repository\PollVoteRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function hash_hmac;

/**
 * One reader's vote on a poll zone, counted once.
 *
 * The reader is a keyed fingerprint of their address and browser, never
 * stored in clear - which also makes it soft: another browser is another
 * reader, and the per-address rate limit is what bounds that. The unique
 * index on (publication, zone, reader) keeps it to one vote each, and a
 * second vote racing the first loses at the insert rather than counting.
 */
final readonly class PollVoteRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PollVoteRepository $pollVoteRepository,
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {}

    public function record(PostInterface $post, string $zoneId, int $answer, ?string $clientIp, string $userAgent): void
    {
        $postId = (int) $post->getId();
        $voter = hash_hmac('sha256', $clientIp.'|'.$userAgent.'|'.$postId.'|'.$zoneId, $this->secret);

        if ($this->pollVoteRepository->hasVoted($postId, $zoneId, $voter)) {
            return;
        }

        try {
            $this->entityManager->persist(new PollVote($post, $zoneId, $answer, $voter));
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two clicks racing: the second lost, and the first counted.
        }
    }
}
