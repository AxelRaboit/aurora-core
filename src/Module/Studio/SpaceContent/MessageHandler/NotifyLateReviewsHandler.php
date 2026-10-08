<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\MessageHandler;

use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceActivityNotifier;
use Aurora\Module\Studio\SpaceContent\Message\NotifyLateReviewsMessage;
use Aurora\Module\Studio\SpaceContent\Workload\SpaceWorkload;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The reminder of a review that is overdue, sent to the studio.
 *
 * **The studio, never the client.** The client has already been asked; what
 * is late is the studio's to chase, by hand, with « Envoyer à relire » if it
 * wants to. An automatic email to somebody else's customer is the kind of
 * feature that becomes a complaint.
 *
 * Once a day, one notification per space that has any, counted by
 * {@see SpaceWorkload} like everywhere else. It is not repeated while the
 * last one is unread: somebody who has not looked yet does not need a second
 * bell for the same thing.
 */
#[AsMessageHandler]
final readonly class NotifyLateReviewsHandler
{
    public function __construct(
        private CustomerSpaceRepository $spaceRepository,
        private SpaceWorkload $workload,
        private SpaceActivityNotifier $notifier,
    ) {}

    public function __invoke(NotifyLateReviewsMessage $message): void
    {
        $spaces = [];
        foreach ($this->spaceRepository->findAllOrdered() as $space) {
            $spaces[(int) $space->getId()] = $space;
        }

        foreach ($this->workload->forSpaces(array_values($spaces)) as $row) {
            if ($row->lateReview > 0) {
                $this->notifier->reviewsLate($spaces[$row->spaceId], $row->lateReview);
            }
        }
    }
}
