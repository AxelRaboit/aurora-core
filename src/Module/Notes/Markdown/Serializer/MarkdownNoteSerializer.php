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

    /** @var array<int, string> */
    protected array $favorites = [];

    /**
     * The excerpts to attach to the rows, from a grouped query.
     *
     * A clone rather than state set on the service: the serializer is shared,
     * and a list of excerpts left behind would follow the next query.
     *
     * @param array<int, string> $excerpts
     */
    public function withExcerpts(array $excerpts): static
    {
        $clone = clone $this;
        $clone->excerpts = $excerpts;

        return $clone;
    }

    public function withFavorites(array $favorites): static
    {
        $clone = clone $this;
        $clone->favorites = $favorites;

        return $clone;
    }

    public function serializeListItem(MarkdownNoteInterface $note): array
    {
        return [
            'id' => $note->getId(),
            'version' => $note->getVersion(),
            'folderId' => $note->getFolder()?->getId(),
            'title' => $note->getTitle(),
            'tags' => $note->getTags(),
            'position' => $note->getPosition(),
            'template' => $note->isTemplate(),
            'createdAt' => $note->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updatedAt' => $note->getUpdatedAt()->format(DateTimeInterface::ATOM),
            'excerpt' => $this->excerpts[(int) $note->getId()] ?? null,
            'favoritedAt' => $this->favorites[(int) $note->getId()] ?? null,
            'spaceId' => $note->getSpace()->getId(),
            // Who wrote it, so that the screen can say "partagé par".
            // The id alone says nothing to anyone.
            'ownerId' => $note->getUser()?->getId(),
            'ownerName' => $note->getUser()?->getName(),
            'coverUrl' => $note->getCoverUrl(),
            'coverCreditName' => $note->getCoverCreditName(),
            'coverCreditUrl' => $note->getCoverCreditUrl(),
            'coverPosition' => $note->getCoverPosition(),
            'appearance' => $note->getAppearance()->value,
            // Where it was copied from, to offer to bring it back to the
            // current version of the document.
            'craftDocumentId' => $note->getCraftDocumentId(),
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
