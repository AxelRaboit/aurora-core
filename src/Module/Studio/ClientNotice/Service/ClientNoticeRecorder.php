<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Service;

use Aurora\Module\Studio\ClientNotice\Entity\ClientNotice;
use Aurora\Module\Studio\ClientNotice\Entity\ClientNoticeInterface;
use Aurora\Module\Studio\ClientNotice\Enum\ClientNoticeTypeEnum;
use Aurora\Module\Studio\ClientNotice\Message\ClientDigestMessage;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Enum\ClientDigestModeEnum;
use Aurora\Module\Studio\CustomerSpace\Service\SpaceActivityNotifier;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Repository\SpaceAccessLinkRepository;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItemInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

/**
 * Telling a client what the studio did.
 *
 * **The half the spaces were missing.** {@see SpaceActivityNotifier}
 * tells the studio what the client did; nothing told the client anything,
 * short of the review invitation somebody had to send by hand. A message
 * from the team, a file shared, a content to approve reached the client the
 * day they happened to open their page.
 *
 * **Written always, mailed by choice.** Each piece of news becomes a
 * {@see ClientNoticeInterface} for every link it concerns, whatever the
 * space's setting: the client's page reads them at the next visit. Only the
 * mail depends on {@see ClientDigestModeEnum}, and with « délai » a look is
 * queued for half an hour later.
 *
 * **Rights are applied here.** A link that may not approve is not told a
 * content awaits its opinion; one that may not chat is not told of a
 * message. The caller says who the news concerns with a predicate.
 *
 * Nothing here can fail the gesture it reports: the caller has saved it, and
 * news that cannot be stored is news that is lost, not an error.
 */
readonly class ClientNoticeRecorder
{
    /**
     * Half an hour after the studio's last gesture.
     *
     * Long enough for a batch - five files, a comment, a message - to become
     * one mail; short enough that the client hears of it the same afternoon.
     */
    public const int DELAY_MS = 1800000;

    public function __construct(
        private SpaceAccessLinkRepository $linkRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {}

    /**
     * @param (callable(SpaceAccessLinkInterface): bool)|null $concerns null for every link
     *
     * @return int the links told
     */
    public function record(CustomerSpaceInterface $space, ClientNoticeTypeEnum $type, ?string $subject, ?callable $concerns = null): int
    {
        if ($space->isTrashed()) {
            return 0;
        }

        try {
            $links = $this->linkRepository->findUsableForSpace($space, new DateTimeImmutable());
        } catch (Throwable) {
            return 0;
        }

        $told = [];

        foreach ($links as $link) {
            if (null !== $concerns && !$concerns($link)) {
                continue;
            }

            $notice = $this->createNotice();
            $notice->setLink($link)->setType($type)->setSubject($subject);
            $this->entityManager->persist($notice);
            $told[] = $link;
        }

        if ([] === $told) {
            return 0;
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable) {
            return 0;
        }

        if (ClientDigestModeEnum::Delayed === $space->getClientDigest()) {
            foreach ($told as $link) {
                try {
                    $this->bus->dispatch(new ClientDigestMessage((int) $link->getId()), [new DelayStamp(self::DELAY_MS)]);
                } catch (Throwable) {
                }
            }
        }

        return count($told);
    }

    /**
     * News about a content, told only when the client sees that content.
     *
     * A card in « Rédaction » is the studio's business: a comment on it, a
     * file attached to it, is not news for a client who cannot open it.
     *
     * @param (callable(SpaceAccessLinkInterface): bool)|null $concerns
     */
    public function recordForItem(SpaceContentItemInterface $item, ClientNoticeTypeEnum $type, ?callable $concerns = null): int
    {
        if (!$item->isShownToClient()) {
            return 0;
        }

        return $this->record($item->getSpace(), $type, $item->getTitle(), $concerns);
    }

    /** Who may answer: the links with the right to approve. */
    public static function approvers(): callable
    {
        return static fn (SpaceAccessLinkInterface $link): bool => $link->canApprove();
    }

    /** Who reads the conversation: the links with the right to chat. */
    public static function chatters(): callable
    {
        return static fn (SpaceAccessLinkInterface $link): bool => $link->canChat();
    }

    /** Who reads the threads under the contents: those who may comment or answer. */
    public static function commenters(): callable
    {
        return static fn (SpaceAccessLinkInterface $link): bool => $link->canComment() || $link->canApprove();
    }

    /**
     * Instantiates the concrete entity. Override in a subclass to return a
     * client-substituted class.
     */
    protected function createNotice(): ClientNoticeInterface
    {
        return new ClientNotice();
    }
}
