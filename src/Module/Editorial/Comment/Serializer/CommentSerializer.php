<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Comment\Serializer;

use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Enum\ReactionTypeEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const DATE_ATOM;

#[AsAlias(CommentSerializerInterface::class)]
class CommentSerializer implements CommentSerializerInterface
{
    public function serialize(CommentInterface $comment, array $reactionCounts = []): array
    {
        return [
            ...$this->shared($comment, $reactionCounts),
            'postId' => $comment->getPost()->getId(),
            'postTitle' => $this->postTitle($comment),
            'authorEmail' => $comment->getAuthorEmail(),
            'status' => $comment->getStatus()->value,
            'parentAuthorName' => $comment->getParent()?->getAuthorName(),
            'replyCount' => $comment->getReplies()->count(),
        ];
    }

    public function serializeForReader(CommentInterface $comment, array $reactionCounts = []): array
    {
        return $this->shared($comment, $reactionCounts);
    }

    public function serializeThread(array $comments, array $reactionCounts = []): array
    {
        $byParent = [];
        foreach ($comments as $comment) {
            $byParent[$comment->getParent()?->getId() ?? 0][] = $this->serializeForReader($comment, $reactionCounts);
        }

        $roots = [];
        foreach ($byParent[0] ?? [] as $root) {
            // One level of nesting. A thread that indents forever is
            // unreadable on a phone, so a reply to a reply answers the root:
            // the conversation stays flat below the first fold.
            $root['replies'] = $byParent[$root['id']] ?? [];
            $roots[] = $root;
        }

        return [
            'comments' => $roots,
            'total' => count($comments),
            'reactionTypes' => $this->reactionTypes(),
        ];
    }

    /**
     * The emoji travel with the payload rather than being duplicated in the
     * Vue component: the enum is where the vocabulary lives, and a component
     * carrying its own copy is one that drifts the day a reaction is added.
     *
     * @return list<array{value: string, emoji: string, labelKey: string}>
     */
    private function reactionTypes(): array
    {
        return array_map(
            static fn (ReactionTypeEnum $case): array => [
                'value' => $case->value,
                'emoji' => $case->emoji(),
                'labelKey' => $case->labelKey(),
            ],
            ReactionTypeEnum::cases(),
        );
    }

    /**
     * @param array<int, array<string, int>> $reactionCounts
     *
     * @return array<string, mixed>
     */
    private function shared(CommentInterface $comment, array $reactionCounts): array
    {
        $id = (int) $comment->getId();

        return [
            'id' => $comment->getId(),
            'reference' => $comment->getReference(),
            'authorName' => $comment->getAuthorName(),
            'content' => $comment->getContent(),
            'createdAt' => $comment->getCreatedAt()->format(DATE_ATOM),
            'parentId' => $comment->getParent()?->getId(),
            'reactions' => $reactionCounts[$id] ?? [],
        ];
    }

    private function postTitle(CommentInterface $comment): string
    {
        $translation = $comment->getPost()->getTranslations()->first() ?: null;

        return $translation?->getTitle() ?? '';
    }
}
