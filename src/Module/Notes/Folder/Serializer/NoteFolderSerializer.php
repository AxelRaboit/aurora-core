<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Serializer;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(NoteFolderSerializerInterface::class)]
class NoteFolderSerializer implements NoteFolderSerializerInterface
{
    /** @var array<int, int> */
    protected array $noteCounts = [];

    /** @var array<int, int> */
    protected array $childCounts = [];

    /**
     * The counts arrive from one grouped query rather than from the folder,
     * which would count its contents one card at a time.
     */
    public function withCounts(array $noteCounts, array $childCounts): static
    {
        $clone = clone $this;
        $clone->noteCounts = $noteCounts;
        $clone->childCounts = $childCounts;

        return $clone;
    }

    public function serialize(NoteFolderInterface $folder): array
    {
        $id = (int) $folder->getId();

        return [
            'id' => $folder->getId(),
            'parentId' => $folder->getParent()?->getId(),
            'name' => $folder->getName(),
            'position' => $folder->getPosition(),
            'noteCount' => $this->noteCounts[$id] ?? 0,
            'folderCount' => $this->childCounts[$id] ?? 0,
            'createdAt' => $folder->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updatedAt' => $folder->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
