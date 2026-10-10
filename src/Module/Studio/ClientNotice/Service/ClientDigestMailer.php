<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\ClientNotice\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Studio\ClientNotice\Entity\ClientNoticeInterface;
use Aurora\Module\Studio\ClientNotice\Enum\ClientNoticeTypeEnum;
use Aurora\Module\Studio\ClientNotice\Repository\ClientNoticeRepository;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLinkInterface;
use Aurora\Module\Studio\SpaceAccess\Service\SpaceLinkMailer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one mail that tells a client what the studio did since they last
 * looked.
 *
 * **One line per kind of news, not one per event.** « 3 messages de
 * l'équipe », « 2 fichiers partagés : logo.png, devis.pdf »: a client reads
 * what is waiting for them, not a log. The names are the first few, because
 * that is what tells somebody whether to open it now.
 *
 * **Written in the customer's language**, from the stored subjects: the
 * sentence is built here, at sending time, never when the news was recorded.
 *
 * **Never twice.** Every notice it carries is marked as mailed, and a notice
 * the client has seen on their page is not carried at all. A link that no
 * longer opens anything is not written to, and its notices stay as they are.
 */
readonly class ClientDigestMailer
{
    /** How many names a line lists before « … ». */
    private const int NAMES_PER_LINE = 3;

    public function __construct(
        private ClientNoticeRepository $noticeRepository,
        private SpaceLinkMailer $linkMailer,
        private MailService $mailService,
        private EntityManagerInterface $entityManager,
    ) {}

    /** @return bool whether a mail went out */
    public function send(SpaceAccessLinkInterface $link): bool
    {
        $notices = $this->noticeRepository->findPendingForLink($link);
        if ([] === $notices) {
            return false;
        }

        $url = $this->linkMailer->addressOf($link);
        if (null === $url) {
            return false;
        }

        $space = $link->getSpace();

        $this->mailService->send(
            to: $link->getRecipientEmail(),
            subjectKey: 'studio.email.client_digest.subject',
            template: '@Studio/email/client_digest.html.twig',
            context: [
                'space' => $space,
                'lines' => $this->lines($notices),
                'url' => $url,
            ],
            locale: $this->linkMailer->localeOf($space),
            subjectParameters: ['{space}' => $space->getName()],
        );

        $now = new DateTimeImmutable();
        foreach ($notices as $notice) {
            $notice->markEmailed($now);
        }

        $this->entityManager->flush();

        return true;
    }

    /**
     * The notices folded into lines, in the order of the enum: what to answer
     * first, then what to read.
     *
     * @param list<ClientNoticeInterface> $notices
     *
     * @return list<array{type: string, view: string, key: string, count: int, names: string}>
     */
    public function lines(array $notices): array
    {
        $byType = [];
        foreach ($notices as $notice) {
            $byType[$notice->getType()->value][] = $notice->getSubject();
        }

        $lines = [];
        foreach (ClientNoticeTypeEnum::cases() as $type) {
            $subjects = $byType[$type->value] ?? [];
            if ([] === $subjects) {
                continue;
            }

            $names = array_values(array_unique(array_filter($subjects, static fn (?string $subject): bool => null !== $subject && '' !== $subject)));
            $shown = array_slice($names, 0, self::NAMES_PER_LINE);

            $lines[] = [
                'type' => $type->value,
                'view' => $type->view(),
                'key' => $type->lineKey(),
                'count' => count($subjects),
                'names' => implode(', ', $shown).(count($names) > self::NAMES_PER_LINE ? '…' : ''),
            ];
        }

        return $lines;
    }
}
