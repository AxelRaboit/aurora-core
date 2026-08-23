<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Post\Message\PurgeTrashedPostsMessage;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeTrashedPostsHandler
{
    public function __construct(
        private PostRepository $postRepository,
        private SettingRepository $settingRepository,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeTrashedPostsMessage $message): void
    {
        $days = (int) $this->settingRepository->get(
            ApplicationParameterEnum::TrashAutoPurgeDays->value,
            ApplicationParameterEnum::TrashAutoPurgeDays->getDefaultValue(),
        );

        // Zero turns automatic purging off - the trash then keeps everything
        // until someone empties it by hand.
        if ($days <= 0) {
            return;
        }

        $cutoff = new DateTimeImmutable(sprintf('-%d days', $days));
        $purgeable = $this->postRepository->findTrashedBefore($cutoff);

        if ([] === $purgeable) {
            return;
        }

        foreach ($purgeable as $post) {
            // Logged before removal: afterwards there is no id to record.
            $this->auditLogger->log('editorial', 'post.purged', 'Post', $post->getId(), [
                'trashedAt' => $post->getDeletedAt()?->format(DATE_ATOM),
            ]);

            $this->entityManager->remove($post);
        }

        $this->entityManager->flush();

        $this->logger->info('Purged {count} trashed post(s) older than {days} days.', [
            'count' => count($purgeable),
            'days' => $days,
        ]);
    }
}
