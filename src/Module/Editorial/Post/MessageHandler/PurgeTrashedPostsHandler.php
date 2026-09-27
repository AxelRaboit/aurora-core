<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\MessageHandler;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
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

        // Logged before removal, since afterwards there is no id to record,
        // and all together: one line's flush inside the loop also executed the
        // removal queued before it, a row at a time.
        $this->auditLogger->logMany('editorial', 'post.purged', 'Post', array_map(
            static fn (PostInterface $post): array => ['id' => $post->getId(), 'data' => [
                'trashedAt' => $post->getDeletedAt()?->format(DATE_ATOM),
            ]],
            $purgeable,
        ));

        foreach ($purgeable as $post) {
            $this->entityManager->remove($post);
        }

        $this->entityManager->flush();

        $this->logger->info('Purged {count} trashed post(s) older than {days} days.', [
            'count' => count($purgeable),
            'days' => $days,
        ]);
    }
}
