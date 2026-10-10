<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\MessageHandler;

use Aurora\Module\Studio\ClientNotice\Message\SendDailyClientDigestsMessage;
use Aurora\Module\Studio\ClientNotice\Repository\ClientNoticeRepository;
use Aurora\Module\Studio\ClientNotice\Service\ClientDigestMailer;
use Aurora\Module\Studio\CustomerSpace\Enum\ClientDigestModeEnum;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * The morning digest, for the spaces that write once a day.
 *
 * One mail per link with something unseen. A mail that fails leaves its
 * notices unmailed, for tomorrow, and does not stop the others: one client's
 * mailbox is not a reason for the rest to hear nothing.
 */
#[AsMessageHandler]
final readonly class SendDailyClientDigestsHandler
{
    public function __construct(
        private ClientNoticeRepository $noticeRepository,
        private ClientDigestMailer $mailer,
    ) {}

    public function __invoke(SendDailyClientDigestsMessage $message): void
    {
        foreach ($this->noticeRepository->findLinksWithPendingIn(ClientDigestModeEnum::Daily) as $link) {
            try {
                $this->mailer->send($link);
            } catch (Throwable) {
            }
        }
    }
}
