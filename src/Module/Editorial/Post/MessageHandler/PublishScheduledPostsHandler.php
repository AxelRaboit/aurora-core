<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\MessageHandler;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Message\PublishScheduledPostsMessage;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PublishScheduledPostsHandler
{
    public function __construct(
        private PostRepository $postRepository,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PublishScheduledPostsMessage $message): void
    {
        $now = new DateTimeImmutable();
        $due = $this->postRepository->findDueForPublication($now);

        if ([] === $due) {
            return;
        }

        foreach ($due as $post) {
            $post->setStatus(PostStatusEnum::Published);

            // The date the editor asked for, not the minute the worker woke
            // up: a post scheduled for 09:00 that publishes at 09:00:37 still
            // belongs at 09:00 in a date-ordered listing.
            if (!$post->getPublishedAt() instanceof DateTimeImmutable) {
                $post->setPublishedAt($post->getScheduledAt() ?? $now);
            }

            $post->setScheduledAt(null);
        }

        $this->entityManager->flush();

        // Audited one by one: a post going live is the kind of change an
        // editor comes looking for afterwards, and nobody was at the keyboard
        // to remember it happened.
        foreach ($due as $post) {
            $this->auditLogger->log('editorial', 'post.published_on_schedule', 'Post', $post->getId(), [
                'status' => $post->getStatus()->value,
            ]);
        }

        $this->logger->info('Published {count} scheduled post(s).', ['count' => count($due)]);
    }
}
