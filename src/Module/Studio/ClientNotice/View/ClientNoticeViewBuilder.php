<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\View;

use Aurora\Module\Studio\ClientNotice\Repository\ClientNoticeRepository;
use Aurora\Module\Studio\ClientNotice\Service\ClientDigestMailer;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use DateTimeImmutable;

/**
 * « Depuis votre dernière visite », at the top of a client's page.
 *
 * The same lines as the digest mail, folded the same way, each with the tab
 * it opens. Read once: opening the page is what makes them seen, so the next
 * visit shows what came after, and the next mail carries nothing the client
 * has already read here.
 *
 * A preview reads without marking. It is the studio looking over the
 * client's shoulder, and the client has seen nothing yet.
 */
final readonly class ClientNoticeViewBuilder
{
    public function __construct(
        private ClientNoticeRepository $noticeRepository,
        private ClientDigestMailer $digestMailer,
    ) {}

    /** @return array{news: list<array{type: string, view: string, key: string, count: int, names: string}>} */
    public function publicView(SpaceAccessLinkInterface $link): array
    {
        $notices = $this->noticeRepository->findUnseenForLink($link);
        $news = $this->digestMailer->lines($notices);

        if ([] !== $notices && !$link->isPreview()) {
            $this->noticeRepository->markSeenForLink($link, new DateTimeImmutable());
        }

        return ['news' => $news];
    }
}
