<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\MessageHandler;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Message\UnpublishScheduledPostsMessage;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function count;

#[AsMessageHandler]
final readonly class UnpublishScheduledPostsHandler
{
    public function __construct(
        private PostRepository $postRepository,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(UnpublishScheduledPostsMessage $message): void
    {
        $now = new DateTimeImmutable();
        $due = $this->postRepository->findDueForUnpublishing($now);

        if ([] === $due) {
            return;
        }

        foreach ($due as $post) {
            // `Archived`, not `Draft`. It was finished and public; draft would say
            // somebody is still working on it, and it would reappear in the queue
            // an author scans for things to finish.
            $post->setStatus(PostStatusEnum::Archived);

            // The date is cleared once it has fired, like `scheduledAt` is on
            // publication: a date already in the past is one that confuses whoever
            // opens the post next, and leaving it would have this run again on
            // every tick for a post it has already handled.
            $post->setUnpublishAt(null);
        }

        $this->entityManager->flush();

        // One line per post, as the publishing handler does. A page leaving the
        // site is exactly what somebody comes looking for afterwards, and nobody
        // was at the keyboard to remember it happened.
        foreach ($due as $post) {
            $this->auditLogger->log('editorial', 'post.unpublished_on_schedule', 'Post', $post->getId(), [
                'status' => $post->getStatus()->value,
            ]);
        }

        $this->logger->info('Unpublished {count} scheduled post(s).', ['count' => count($due)]);
    }
}
