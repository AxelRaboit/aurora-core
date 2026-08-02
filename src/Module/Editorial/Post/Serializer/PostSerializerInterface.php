<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Serializer;

use Aurora\Module\Editorial\Post\Entity\PostInterface;

interface PostSerializerInterface
{
    /** Just enough to name a post in a picker. @return array<string, mixed> */
    public function serializeReference(PostInterface $post): array;

    /** A row in the backend list. @return array<string, mixed> */
    public function serialize(PostInterface $post): array;

    /** Everything the editor edits, every locale. @return array<string, mixed> */
    public function serializeFull(PostInterface $post): array;

    /** One locale's view, for a public listing card. @return array<string, mixed> */
    public function serializeCard(PostInterface $post, string $locale): array;
}
