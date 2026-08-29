<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Bulk;

use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Manager\PostManagerInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

use function count;

/**
 * One action, applied to a selection.
 *
 * **Partial by design, and it reports how partial.** A list mixes posts with
 * different authors, so a selection of ten can easily contain two the reader may
 * not touch. Refusing the whole request because of those two makes the feature
 * useless exactly where it is most wanted; doing the eight silently is worse still,
 * because the reader counts ten rows and believes it. So it does what it may and
 * answers with both numbers.
 *
 * Every row is checked on its own. The permission lives on the enum rather than
 * here, so adding an action means declaring what it needs rather than remembering
 * to guard it.
 */
final readonly class PostBulkActioner
{
    public function __construct(
        private PostRepository $posts,
        private PostManagerInterface $postManager,
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {}

    /**
     * @param list<int> $ids
     */
    public function apply(PostBulkActionEnum $action, array $ids): PostBulkResult
    {
        if ([] === $ids) {
            return new PostBulkResult(0, 0);
        }

        $done = 0;
        $skipped = 0;

        foreach ($this->posts->findBy(['id' => $ids]) as $post) {
            if ($this->run($action, $post)) {
                ++$done;

                continue;
            }

            ++$skipped;
        }

        // Ids that matched nothing count as skipped too. A selection carrying a
        // post somebody else deleted a moment ago should say so rather than come
        // back reporting fewer rows than were sent with no explanation.
        $skipped += count($ids) - ($done + $skipped);

        $this->entityManager->flush();

        return new PostBulkResult($done, $skipped);
    }

    /** Whether this row was actually acted on. */
    private function run(PostBulkActionEnum $action, PostInterface $post): bool
    {
        if (!$this->security->isGranted($action->attribute(), $post)) {
            return false;
        }

        // A restore aimed at a live post, or a trashing aimed at a trashed one, is
        // a no-op somebody would read as a success.
        if ($action->needsTrashed() !== $post->isTrashed()) {
            return false;
        }

        match ($action) {
            PostBulkActionEnum::Publish => $this->publish($post),
            PostBulkActionEnum::Draft => $post->setStatus(PostStatusEnum::Draft),
            PostBulkActionEnum::Trash => $this->postManager->delete($post),
            PostBulkActionEnum::Restore => $this->postManager->restore($post),
            PostBulkActionEnum::ForceDelete => $this->postManager->forceDelete($post),
        };

        return true;
    }

    /**
     * Publishing dates the post if it has never been dated.
     *
     * The same rule the review's approval follows, and for the same reason: the
     * front and the sitemap order by `publishedAt`, so a post published with a null
     * one sits nowhere.
     */
    private function publish(PostInterface $post): void
    {
        $post->setStatus(PostStatusEnum::Published);

        if (!$post->getPublishedAt() instanceof DateTimeImmutable) {
            $post->setPublishedAt(new DateTimeImmutable());
        }
    }
}
