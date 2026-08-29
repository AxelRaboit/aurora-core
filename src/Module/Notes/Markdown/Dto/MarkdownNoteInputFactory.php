<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MarkdownNoteInputFactoryInterface::class)]
class MarkdownNoteInputFactory implements MarkdownNoteInputFactoryInterface
{
    public function fromArray(array $data): MarkdownNoteInputInterface
    {
        return new MarkdownNoteInput(
            parentId: isset($data['parentId']) ? (int) $data['parentId'] : null,
            title: Str::trimOrNullFromArray($data, 'title'),
            content: $this->stringOrNull($data, 'content'),
            tags: $this->stringList($data['tags'] ?? []),
            position: isset($data['position']) ? (int) $data['position'] : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function stringOrNull(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        $value = $data[$key];

        if (!is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            $trimmed = mb_trim($tag);
            if ('' === $trimmed) {
                continue;
            }

            $tags[] = $trimmed;
        }

        return array_values(array_unique($tags));
    }
}
