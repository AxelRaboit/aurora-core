<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Share\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteShareLinkInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tells somebody a note was shared with them.
 *
 * The mail carries the address and the note's title and nothing of its body.
 * A share link is a secret, and mail is forwarded, quoted and archived by
 * systems nobody in this conversation controls; putting the content in the
 * message would publish it a second time, to a place with no revocation.
 */
final readonly class NoteShareNotifier
{
    public function __construct(
        private MailService $mail,
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $entityManager,
    ) {}

    public function notify(MarkdownNoteShareLinkInterface $link, string $sharerName, ?string $untitledLabel = null): void
    {
        $recipient = $link->getRecipientEmail();

        if (null === $recipient || '' === $recipient) {
            return;
        }

        $title = mb_trim((string) $link->getNote()->getTitle());
        if ('' === $title) {
            $title = $untitledLabel ?? '';
        }

        $this->mail->send(
            $recipient,
            'notes.markdown.share.mail.subject',
            '@Notes/email/note_shared.html.twig',
            [
                'noteTitle' => $title,
                'sharerName' => $sharerName,
                'includesDescendants' => $link->includesDescendants(),
                'expiresAt' => $link->getExpiresAt(),
                'url' => $this->urlGenerator->generate(
                    'notes_share',
                    ['token' => $link->getToken()],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            subjectParams: ['%title%' => $title],
        );

        // Stamped after the send, so a row that says "sent" means the handoff to
        // the mailer actually happened. A throw leaves it null, which reads as
        // "never sent" rather than as a lie.
        $link->markSent(new DateTimeImmutable());
        $this->entityManager->flush();
    }
}
