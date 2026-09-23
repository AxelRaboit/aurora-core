<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Serializer;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MarkdownNoteSerializerInterface::class)]
class MarkdownNoteSerializer implements MarkdownNoteSerializerInterface
{
    /** @var array<int, string> */
    protected array $excerpts = [];

    /**
     * Les extraits à joindre aux lignes, venus d'une requête groupée.
     *
     * Un clone plutôt qu'un état posé sur le service : le sérialiseur est
     * partagé, et une liste d'extraits laissée derrière suivrait la requête
     * suivante.
     *
     * @param array<int, string> $excerpts
     */
    public function withExcerpts(array $excerpts): static
    {
        $clone = clone $this;
        $clone->excerpts = $excerpts;

        return $clone;
    }

    public function serializeListItem(MarkdownNoteInterface $note): array
    {
        return [
            'id' => $note->getId(),
            'folderId' => $note->getFolder()?->getId(),
            'title' => $note->getTitle(),
            'tags' => $note->getTags(),
            'position' => $note->getPosition(),
            'createdAt' => $note->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updatedAt' => $note->getUpdatedAt()->format(DateTimeInterface::ATOM),
            'excerpt' => $this->excerpts[(int) $note->getId()] ?? null,
            'favoritedAt' => $note->getFavoritedAt()?->format(DateTimeInterface::ATOM),
            'coverUrl' => $note->getCoverUrl(),
            'coverCreditName' => $note->getCoverCreditName(),
            'coverCreditUrl' => $note->getCoverCreditUrl(),
            'coverPosition' => $note->getCoverPosition(),
            'appearance' => $note->getAppearance()->value,
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
