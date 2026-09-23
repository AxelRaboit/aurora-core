<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Folder\Serializer;

use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;

interface NoteFolderSerializerInterface
{
    /**
     * @param array<int, int> $noteCounts  folder id => living notes inside it
     * @param array<int, int> $childCounts folder id => living sub-folders
     */
    public function withCounts(array $noteCounts, array $childCounts): static;

    /** @return array<string, mixed> */
    public function serialize(NoteFolderInterface $folder): array;
}
