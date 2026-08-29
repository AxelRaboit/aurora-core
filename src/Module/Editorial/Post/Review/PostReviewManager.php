<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Review;

use Aurora\Core\Notification\Manager\NotificationManagerInterface;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * The three moves a publication makes through review, and who gets told.
 *
 * `pending_review` existed as a status and a badge colour and nothing else: a post
 * was demoted into it silently, nobody was notified, and the way out was for
 * somebody to happen to notice. Every part of this class is one of the missing
 * halves of that.
 *
 * Notifications are the point, not decoration. A review queue nobody is told about
 * is a queue nobody clears, which is what made `pending_review` look like a status
 * that did not work.
 */
class PostReviewManager implements PostReviewManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly NotificationManagerInterface $notifications,
        protected readonly UserRepository $users,
        protected readonly TranslatorInterface $translator,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly AuditLogger $auditLogger,
    ) {}

    /**
     * A draft goes up for review, and the people who can approve it hear about it.
     *
     * Called by the manager when somebody without the right to publish tries to,
     * which is how a post reaches this status. The demotion already happened; what
     * was missing was everything after it.
     */
    public function submit(PostInterface $post, ?CoreUserInterface $author = null): void
    {
        // A resubmission clears the last decision. A note about a version that no
        // longer exists sends the reviewer looking for a problem already fixed.
        $post->setReviewNote(null);
        $post->setReviewedAt(null);
        $post->setReviewedBy(null);

        $title = $this->titleOf($post);

        foreach ($this->reviewers() as $reviewer) {
            // Not the author, even when they can publish: being told about your own
            // submission is noise, and it is the one thing you already know.
            if ($author instanceof CoreUserInterface && $reviewer->getId() === $author->getId()) {
                continue;
            }

            $this->notifications->notify(
                $reviewer,
                'editorial.post.review_requested',
                $title,
                $this->translator->trans('backend.posts.review.notification_submitted', [
                    '%author%' => $author?->getName() ?? '',
                ]),
                $this->editUrl($post),
                ['postId' => $post->getId()],
            );
        }

        $this->auditLogger->log('editorial', 'post.review_requested', 'Post', $post->getId(), [
            'title' => $title,
        ]);

        $this->entityManager->flush();
    }

    public function approve(PostInterface $post, CoreUserInterface $reviewer, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();

        $post->setStatus(PostStatusEnum::Published);
        $post->setReviewNote(null);
        $this->stamp($post, $reviewer, $now);

        // `publishedAt` is what the front and the sitemap order by, so an approval
        // that left it null would publish a post dated nowhere.
        if (!$post->getPublishedAt() instanceof DateTimeImmutable) {
            $post->setPublishedAt($now);
        }

        $this->tellAuthor($post, $reviewer, 'editorial.post.review_approved', 'backend.posts.review.notification_approved');

        $this->auditLogger->log('editorial', 'post.review_approved', 'Post', $post->getId(), [
            'title' => $this->titleOf($post),
        ]);

        $this->entityManager->flush();
    }

    /**
     * Sends it back, with the reason attached.
     *
     * Back to `draft` rather than to a `rejected` status: the author's next move is
     * to edit, and a status that only means "edit this" is a word for what draft
     * already is. The note is what carries the decision.
     */
    public function reject(
        PostInterface $post,
        CoreUserInterface $reviewer,
        string $note,
        ?DateTimeImmutable $now = null,
    ): void {
        $now ??= new DateTimeImmutable();

        $post->setStatus(PostStatusEnum::Draft);
        $post->setReviewNote($note);
        $this->stamp($post, $reviewer, $now);

        $this->tellAuthor($post, $reviewer, 'editorial.post.review_rejected', 'backend.posts.review.notification_rejected');

        $this->auditLogger->log('editorial', 'post.review_rejected', 'Post', $post->getId(), [
            'title' => $this->titleOf($post),
        ]);

        $this->entityManager->flush();
    }

    /**
     * Everybody who may approve.
     *
     * Filtered in PHP rather than queried, because privileges live in a JSON column
     * and a backend has tens of accounts, not thousands. If that stops being true
     * this becomes a JSONB containment query - but writing one now would be a
     * clever answer to a question nobody is asking.
     *
     * @return list<CoreUserInterface>
     */
    protected function reviewers(): array
    {
        $found = [];

        foreach ($this->users->findBy(['type' => UserTypeEnum::Backend->value]) as $user) {
            if ($user->hasPrivilege('editorial.posts.publish')) {
                $found[] = $user;
            }
        }

        return $found;
    }

    private function stamp(PostInterface $post, CoreUserInterface $reviewer, DateTimeImmutable $now): void
    {
        $post->setReviewedAt($now);
        $post->setReviewedBy($reviewer);
    }

    /**
     * Tells whoever wrote it what happened.
     *
     * Nothing to send when the post has no author - a demo row, or one whose
     * account is gone - and nothing worth sending when the reviewer is the author,
     * which happens the moment somebody is granted the right to publish their own
     * work.
     */
    private function tellAuthor(
        PostInterface $post,
        CoreUserInterface $reviewer,
        string $type,
        string $bodyKey,
    ): void {
        $author = $post->getAuthor();

        if (!$author instanceof CoreUserInterface || $author->getId() === $reviewer->getId()) {
            return;
        }

        $this->notifications->notify(
            $author,
            $type,
            $this->titleOf($post),
            $this->translator->trans($bodyKey, ['%reviewer%' => $reviewer->getName()]),
            $this->editUrl($post),
            ['postId' => $post->getId()],
        );
    }

    /**
     * The post's name, in whichever language it has one.
     *
     * A draft is often written in one language first, and a notification headed by
     * an empty string tells the reader nothing about what is waiting.
     */
    private function titleOf(PostInterface $post): string
    {
        foreach ($post->getTranslations() as $translation) {
            $title = $translation->getTitle();

            if (null !== $title && '' !== $title) {
                return $title;
            }
        }

        return sprintf('#%d', $post->getId());
    }

    private function editUrl(PostInterface $post): string
    {
        return $this->urlGenerator->generate(
            'backend_editorial_posts_edit',
            ['id' => $post->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
