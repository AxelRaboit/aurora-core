<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The two emails a comment can cause.
 *
 * Neither is sent for spam - see CommentManager. Both are best-effort: a
 * mail server being down must not lose the comment that triggered it, which
 * is why nothing here reports failure upwards.
 */
readonly class CommentNotificationService
{
    public function __construct(
        private MailService $mail,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function notifyPendingToAdmin(CommentInterface $comment): void
    {
        $this->mail->sendToAdmin(
            'editorial.mail.comment.subject_pending',
            '@Editorial/email/comment_pending.html.twig',
            [
                'comment' => $comment,
                'moderationUrl' => $this->urlGenerator->generate(
                    'backend_editorial_comments',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
        );
    }

    /**
     * Tells the post's author that a comment went live on their piece - not
     * the commenter. Sending to the address typed into a public form would
     * make the site a relay for whatever anyone puts there.
     */
    public function notifyApprovedToAuthor(CommentInterface $comment): void
    {
        $author = $comment->getPost()->getAuthor();
        if (!$author instanceof CoreUserInterface) {
            return;
        }

        $this->mail->send(
            $author->getEmail(),
            'editorial.mail.comment.subject_approved',
            '@Editorial/email/comment_approved.html.twig',
            ['comment' => $comment, 'author' => $author],
            locale: $author->getLocale()->value,
        );
    }
}
