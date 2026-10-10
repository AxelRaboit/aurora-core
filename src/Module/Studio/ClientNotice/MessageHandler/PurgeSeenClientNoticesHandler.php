<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\MessageHandler;

use Aurora\Module\Studio\ClientNotice\Message\PurgeSeenClientNoticesMessage;
use Aurora\Module\Studio\ClientNotice\Repository\ClientNoticeRepository;
use DateTimeImmutable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** News read more than ninety days ago is not news: it goes. */
#[AsMessageHandler]
final readonly class PurgeSeenClientNoticesHandler
{
    public function __construct(
        private ClientNoticeRepository $noticeRepository,
    ) {}

    public function __invoke(PurgeSeenClientNoticesMessage $message): void
    {
        $this->noticeRepository->deleteSeenBefore(new DateTimeImmutable('-90 days'));
    }
}
