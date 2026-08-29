<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Serializer;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MarkdownNoteSerializerInterface::class)]
class MarkdownNoteSerializer implements MarkdownNoteSerializerInterface
{
    public function serializeListItem(MarkdownNoteInterface $note): array
    {
        return [
            'id' => $note->getId(),
            'parentId' => $note->getParent()?->getId(),
            'title' => $note->getTitle(),
            'tags' => $note->getTags(),
            'position' => $note->getPosition(),
            'createdAt' => $note->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updatedAt' => $note->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    public function serializeDetail(MarkdownNoteInterface $note): array
    {
        return [
            ...$this->serializeListItem($note),
            'content' => $note->getContent(),
        ];
    }

    public function serializeTagCounts(array $counts): array
    {
        $tags = [];
        foreach ($counts as $tag => $count) {
            $tags[] = ['tag' => $tag, 'count' => $count];
        }

        return $tags;
    }
}
