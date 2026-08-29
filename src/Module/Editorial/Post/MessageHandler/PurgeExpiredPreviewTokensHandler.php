<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\MessageHandler;

use Aurora\Module\Editorial\Post\Message\PurgeExpiredPreviewTokensMessage;
use Aurora\Module\Editorial\Post\Preview\Repository\PostPreviewTokenRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeExpiredPreviewTokensHandler
{
    public function __construct(
        private PostPreviewTokenRepository $tokens,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(PurgeExpiredPreviewTokensMessage $message): void
    {
        $deleted = $this->tokens->deleteExpired(new DateTimeImmutable());

        if (0 === $deleted) {
            return;
        }

        // Not audited, unlike a post going live. Nobody comes looking for when a
        // dead preview link was tidied away, and a nightly audit row per token
        // would bury the entries somebody does read.
        $this->logger->info('Purged {count} expired preview token(s).', ['count' => $deleted]);
    }
}
