<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\MessageHandler;

use Aurora\Module\Studio\ClientNotice\Message\ClientDigestMessage;
use Aurora\Module\Studio\ClientNotice\Repository\ClientNoticeRepository;
use Aurora\Module\Studio\ClientNotice\Service\ClientDigestMailer;
use Aurora\Module\Studio\ClientNotice\Service\ClientNoticeRecorder;
use Aurora\Module\Studio\CustomerSpace\Enum\ClientDigestModeEnum;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use DateTimeImmutable;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The second look, half an hour after a piece of news.
 *
 * **Only the last look writes.** Every piece of news queues one; the batch is
 * finished when the newest notice is as old as the delay. Before that, a later
 * look is already on its way and this one stays quiet - which is what turns
 * an afternoon of gestures into one mail.
 *
 * The space's choice is read again here rather than when the news was
 * written: a space switched off in the meantime sends nothing.
 */
#[AsMessageHandler]
final readonly class ClientDigestHandler
{
    /** A minute of slack: the queue does not run to the millisecond. */
    private const int SLACK_SECONDS = 60;

    public function __construct(
        private SpaceAccessLinkRepository $linkRepository,
        private ClientNoticeRepository $noticeRepository,
        private ClientDigestMailer $mailer,
    ) {}

    public function __invoke(ClientDigestMessage $message): void
    {
        $link = $this->linkRepository->find($message->linkId);
        if (null === $link || ClientDigestModeEnum::Delayed !== $link->getSpace()->getClientDigest()) {
            return;
        }

        $pending = $this->noticeRepository->findPendingForLink($link);
        if ([] === $pending) {
            return;
        }

        $newest = $pending[count($pending) - 1]->getCreatedAt();
        $settledAt = $newest->modify(sprintf('+%d seconds', intdiv(ClientNoticeRecorder::DELAY_MS, 1000) - self::SLACK_SECONDS));

        if ($settledAt > new DateTimeImmutable()) {
            return;
        }

        $this->mailer->send($link);
    }
}
