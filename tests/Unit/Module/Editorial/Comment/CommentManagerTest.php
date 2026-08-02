<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Comment;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Editorial\Comment\Dto\CommentInput;
use Aurora\Module\Editorial\Comment\Entity\Comment;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Comment\Manager\CommentManager;
use Aurora\Module\Editorial\Comment\Service\CommentNotificationService;
use Aurora\Module\Editorial\Comment\Service\CommentSpamDetector;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Entity\Post;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Spam must be decided before anything is written or sent.
 *
 * The reference did it the other way round: a decorator called `submit()`,
 * which persisted the comment, wrote the audit entry **and sent the email**,
 * and only then flipped the result to spam. Every spam comment therefore
 * mailed the administrator — the one outcome a spam filter exists to
 * prevent — and with moderation switched off it mailed the address the
 * spammer had typed, which turns the site into a relay for anyone who can
 * fill a form.
 *
 * Nothing in the application reported this. The comment did land in the spam
 * queue, so the screen looked right; only the inbox disagreed.
 */
final class CommentManagerTest extends TestCase
{
    private CommentNotificationService&MockObject $notifications;

    protected function setUp(): void
    {
        $this->notifications = $this->createMock(CommentNotificationService::class);
    }

    public function testSpamIsFiledWithoutTellingAnybody(): void
    {
        $this->notifications->expects(self::never())->method('notifyPendingToAdmin');
        $this->notifications->expects(self::never())->method('notifyApprovedToAuthor');

        $comment = $this->submit($this->spamInput());

        self::assertSame(CommentStatusEnum::Spam, $comment->getStatus());
    }

    /** With moderation off, spam must still not reach the address it carried. */
    public function testSpamIsFiledWithoutTellingAnybodyEvenWithModerationOff(): void
    {
        $this->notifications->expects(self::never())->method('notifyApprovedToAuthor');

        $comment = $this->submit($this->spamInput(), moderated: false);

        self::assertSame(CommentStatusEnum::Spam, $comment->getStatus());
    }

    public function testAGenuineCommentUnderModerationWaitsAndTellsTheAdministrator(): void
    {
        $this->notifications->expects(self::once())->method('notifyPendingToAdmin');
        $this->notifications->expects(self::never())->method('notifyApprovedToAuthor');

        $comment = $this->submit($this->genuineInput());

        self::assertSame(CommentStatusEnum::Pending, $comment->getStatus());
    }

    public function testAGenuineCommentWithoutModerationGoesLiveAndTellsThePostAuthor(): void
    {
        $this->notifications->expects(self::never())->method('notifyPendingToAdmin');
        $this->notifications->expects(self::once())->method('notifyApprovedToAuthor');

        $comment = $this->submit($this->genuineInput(), moderated: false);

        self::assertSame(CommentStatusEnum::Approved, $comment->getStatus());
    }

    public function testApprovingAPendingCommentTellsThePostAuthor(): void
    {
        $this->notifications->expects(self::once())->method('notifyApprovedToAuthor');

        $comment = $this->storedComment(CommentStatusEnum::Pending);
        $this->manager(true)->approve($comment);

        self::assertSame(CommentStatusEnum::Approved, $comment->getStatus());
    }

    /**
     * Re-approving something already live must not tell the author twice —
     * a moderator un-spamming a comment they had flagged by mistake, say.
     */
    public function testApprovingAnAlreadyApprovedCommentSendsNothing(): void
    {
        $this->notifications->expects(self::never())->method('notifyApprovedToAuthor');

        $comment = $this->storedComment(CommentStatusEnum::Approved);
        $this->manager(true)->approve($comment);

        self::assertSame(CommentStatusEnum::Approved, $comment->getStatus());
    }

    /** A comment as it comes back from the database — every column filled. */
    private function storedComment(CommentStatusEnum $status): Comment
    {
        return new Comment()
            ->setPost(new Post())
            ->setAuthorName('Camille')
            ->setAuthorEmail('camille@example.org')
            ->setContent('Already written.')
            ->setStatus($status);
    }

    /**
     * A real generator over a stubbed connection: the class is final, and
     * what it hands back does not matter here — only that asking for a
     * reference does not stop the flow being tested.
     */
    private function sequenceGenerator(): SequenceGenerator
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchOne')->willReturn(1);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willReturn($result);

        return new SequenceGenerator($connection);
    }

    private function spamInput(): CommentInput
    {
        return new CommentInput(
            authorName: 'Spam',
            authorEmail: 'spam@example.org',
            content: 'Great! http://a.example http://b.example',
        );
    }

    private function genuineInput(): CommentInput
    {
        return new CommentInput(
            authorName: 'Camille',
            authorEmail: 'camille@example.org',
            content: 'Thanks for the guide, that was very clear indeed.',
        );
    }

    private function submit(CommentInput $input, bool $moderated = true): CommentInterface
    {
        return $this->manager($moderated)->submit(new Post(), $input);
    }

    private function manager(bool $moderated): CommentManager
    {
        $settingRepository = $this->createStub(SettingRepository::class);
        $settingRepository->method('getBoolean')->willReturn($moderated);
        $settingRepository->method('getOrDefault')->willReturn('CMT');

        return new CommentManager(
            $this->createStub(EntityManagerInterface::class),
            $settingRepository,
            $this->createStub(AuditLogger::class),
            $this->notifications,
            new CommentSpamDetector(),
            $this->sequenceGenerator(),
            new EditorialContext($this->createStub(ModuleAccessChecker::class)),
        );
    }
}
