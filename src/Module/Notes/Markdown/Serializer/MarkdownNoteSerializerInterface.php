<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Serializer;

use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;

interface MarkdownNoteSerializerInterface
{
    /**
     * A copy carrying the excerpts a listing wants to show.
     *
     * @param array<int, string> $excerpts note id => first words of its body
     */
    public function withExcerpts(array $excerpts): static;

    /**
     * Lightweight payload for tree/list views - excludes content for perf.
     *
     * @return array<string, mixed>
     */
    public function serializeListItem(MarkdownNoteInterface $note): array;

    /**
     * Full payload for the detail view - includes content.
     *
     * @return array<string, mixed>
     */
    public function serializeDetail(MarkdownNoteInterface $note): array;

    /**
     * Reshape a `tag => count` histogram into the API list shape
     * `[{tag, count}, …]` for the tag-management modal.
     *
     * @param array<string, int> $counts
     *
     * @return list<array{tag: string, count: int}>
     */
    public function serializeTagCounts(array $counts): array;
}
