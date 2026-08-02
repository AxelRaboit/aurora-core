<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Manager;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Comment\Dto\CommentInputInterface;
use Aurora\Module\Editorial\Comment\Entity\Comment;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Comment\Service\CommentNotificationService;
use Aurora\Module\Editorial\Comment\Service\CommentSpamDetector;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Setting\EditorialSettingEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CommentManagerInterface::class)]
class CommentManager implements CommentManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly SettingRepository $settingRepository,
        protected readonly AuditLogger $auditLogger,
        protected readonly CommentNotificationService $notificationService,
        protected readonly CommentSpamDetector $spamDetector,
        protected readonly SequenceGenerator $sequenceGenerator,
        protected readonly EditorialContext $editorialContext,
    ) {}

    /**
     * The spam verdict is reached before the comment is stored, and it is what
     * decides whether anybody is emailed. Filtering afterwards — submit, then
     * mark — means the notification has already gone out, which is how a spam
     * filter ends up delivering the spam it caught.
     */
    public function submit(PostInterface $post, CommentInputInterface $input, ?CommentInterface $parent = null): CommentInterface
    {
        $comment = $this->createComment();
        $comment->setPost($post);
        $comment->setParent($parent);
        $this->applyInput($comment, $input);
        $comment->setStatus($this->initialStatus($input));

        $this->entityManager->persist($comment);
        $this->entityManager->flush();

        // After the flush: the generator hands out one number per prefix and
        // burning one on a rolled-back insert would leave a gap nobody can
        // explain.
        $comment->setReference($this->sequenceGenerator->next(
            $this->settingRepository->getOrDefault(EditorialSettingEnum::CommentPrefix),
        ));
        $this->entityManager->flush();

        $this->auditSubmitted($comment);
        $this->notify($comment);

        return $comment;
    }

    public function approve(CommentInterface $comment): void
    {
        // Only a first approval is worth an email. Re-approving something
        // that was already live — after a moderator un-spams it, say —
        // should not tell the author again.
        $wasApproved = $comment->isApproved();

        $comment->setStatus(CommentStatusEnum::Approved);
        $this->entityManager->flush();

        $this->auditApproved($comment);

        if (!$wasApproved) {
            $this->notificationService->notifyApprovedToAuthor($comment);
        }
    }

    public function markAsSpam(CommentInterface $comment): void
    {
        $comment->setStatus(CommentStatusEnum::Spam);
        $this->entityManager->flush();

        $this->auditMarkedAsSpam($comment);
    }

    public function delete(CommentInterface $comment): void
    {
        // Replies outlive the comment they answered — deleting one message
        // should not take the conversation under it. The database agrees:
        // the parent column is SET NULL.
        foreach ($comment->getReplies() as $reply) {
            $reply->setParent($comment->getParent());
        }

        $this->auditDeleted($comment);

        $this->entityManager->remove($comment);
        $this->entityManager->flush();
    }

    /**
     * The one question everything asks: can this post take a comment right
     * now. Three answers have to agree — the module is installed and on, the
     * site accepts comments at all, and this post has not closed its own.
     *
     * All three in one place because the alternative is a page that renders
     * a form whose endpoint answers 404: the route gate closes the endpoint
     * when the module is off, and a caller checking only the setting would
     * still draw the form.
     */
    public function areCommentsEnabled(PostInterface $post): bool
    {
        return $this->editorialContext->isCommentsEnabled()
            && $this->settingRepository->getBoolean(ApplicationParameterEnum::CommentsEnabled->value, true)
            && $post->isCommentsEnabled();
    }

    // ── Hooks: instanciation ──────────────────────────────────────────────────

    protected function createComment(): CommentInterface
    {
        return new Comment();
    }

    // ── Hooks: hydratation ────────────────────────────────────────────────────

    protected function applyInput(CommentInterface $comment, CommentInputInterface $input): void
    {
        $comment->setAuthorName($input->getAuthorName());
        $comment->setAuthorEmail($input->getAuthorEmail());
        $comment->setContent($input->getContent());
    }

    // ── Hooks: audit ──────────────────────────────────────────────────────────

    protected function auditSubmitted(CommentInterface $comment): void
    {
        $this->auditLogger->log('editorial', 'comment.submitted', 'Comment', $comment->getId(), $this->auditPayload($comment));
    }

    protected function auditApproved(CommentInterface $comment): void
    {
        $this->auditLogger->log('editorial', 'comment.approved', 'Comment', $comment->getId(), $this->auditPayload($comment));
    }

    protected function auditMarkedAsSpam(CommentInterface $comment): void
    {
        $this->auditLogger->log('editorial', 'comment.spam', 'Comment', $comment->getId(), $this->auditPayload($comment));
    }

    protected function auditDeleted(CommentInterface $comment): void
    {
        $this->auditLogger->log('editorial', 'comment.deleted', 'Comment', $comment->getId(), $this->auditPayload($comment));
    }

    /**
     * A comment has no create/update/delete cycle — it is submitted, approved,
     * flagged or removed — so the actions are named after what happened.
     *
     * @return array<string, mixed>
     */
    protected function auditPayload(CommentInterface $comment): array
    {
        return [
            'postId' => $comment->getPost()->getId(),
            'authorEmail' => $comment->getAuthorEmail(),
            'status' => $comment->getStatus()->value,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function initialStatus(CommentInputInterface $input): CommentStatusEnum
    {
        if ($this->spamDetector->isSpam($input)) {
            return CommentStatusEnum::Spam;
        }

        $moderated = $this->settingRepository->getBoolean(
            ApplicationParameterEnum::CommentModerationEnabled->value,
            true,
        );

        return $moderated ? CommentStatusEnum::Pending : CommentStatusEnum::Approved;
    }

    /**
     * Spam is filed and forgotten. It is kept so a moderator can look at what
     * is being caught, but nobody is told about it — mailing an administrator
     * once per spam comment is how a moderation queue becomes something people
     * stop reading.
     */
    private function notify(CommentInterface $comment): void
    {
        match ($comment->getStatus()) {
            CommentStatusEnum::Pending => $this->notificationService->notifyPendingToAdmin($comment),
            CommentStatusEnum::Approved => $this->notificationService->notifyApprovedToAuthor($comment),
            CommentStatusEnum::Spam => null,
        };
    }
}
