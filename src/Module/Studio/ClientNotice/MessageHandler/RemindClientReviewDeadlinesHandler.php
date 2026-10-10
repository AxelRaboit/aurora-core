<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\MessageHandler;

use Aurora\Module\Studio\ClientNotice\Enum\ClientNoticeTypeEnum;
use Aurora\Module\Studio\ClientNotice\Message\RemindClientReviewDeadlinesMessage;
use Aurora\Module\Studio\ClientNotice\Repository\ClientNoticeRepository;
use Aurora\Module\Studio\ClientNotice\Service\ClientNoticeRecorder;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentItemRepository;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The day before a review is due, the people who may answer are told.
 *
 * **The cause of most late reviews, chased once.** A deadline the client
 * never heard of is a deadline that passes; the studio was told the day
 * after (see `NotifyLateReviewsHandler`), which is a day too late to do
 * anything gentle about it.
 *
 * **Only where the space mails its client.** Writing to somebody else's
 * customer is the studio's decision, taken in the space's settings; a space
 * that sends nothing reminds nobody, and its page shows the deadline as it
 * always did.
 *
 * « Tomorrow » in the space's zone, and once per content: a deadline moved
 * to the next day does not ring a second time within two days.
 */
#[AsMessageHandler]
final readonly class RemindClientReviewDeadlinesHandler
{
    public function __construct(
        private SpaceContentItemRepository $itemRepository,
        private ClientNoticeRepository $noticeRepository,
        private ClientNoticeRecorder $recorder,
    ) {}

    public function __invoke(RemindClientReviewDeadlinesMessage $message): void
    {
        $now = new DateTimeImmutable();
        $twoDaysAgo = $now->modify('-2 days');

        // Wide enough to cover tomorrow in every zone on earth; each card is
        // then held to its own space's calendar.
        foreach ($this->itemRepository->findPendingReviewDueBetween($now, $now->modify('+3 days')) as $item) {
            $space = $item->getSpace();
            $reviewBy = $item->getReviewBy();

            if (null === $reviewBy || !$space->getClientDigest()->sends() || !$item->isShownToClient() || !$item->isAtClientStep()) {
                continue;
            }

            $zone = new DateTimeZone($space->getTimezone());
            $tomorrow = $now->setTimezone($zone)->modify('+1 day')->format('Y-m-d');
            if ($reviewBy->setTimezone($zone)->format('Y-m-d') !== $tomorrow) {
                continue;
            }

            $title = $item->getTitle();
            $this->recorder->record(
                $space,
                ClientNoticeTypeEnum::ReviewDue,
                $title,
                fn (SpaceAccessLinkInterface $link): bool => $link->canApprove()
                    && !$this->noticeRepository->wasTold($link, ClientNoticeTypeEnum::ReviewDue, $title, $twoDaysAgo),
            );
        }
    }
}
