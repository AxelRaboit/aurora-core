<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(MarkdownNoteReorderInputFactoryInterface::class)]
class MarkdownNoteReorderInputFactory implements MarkdownNoteReorderInputFactoryInterface
{
    public function fromArray(array $data): MarkdownNoteReorderInput
    {
        $raw = $data['entries'] ?? null;
        if (!is_array($raw)) {
            return new MarkdownNoteReorderInput(entries: []);
        }

        $entries = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!isset($entry['id'])) {
                continue;
            }

            $entries[] = [
                'id' => (int) $entry['id'],
                'parentId' => isset($entry['parentId']) && '' !== $entry['parentId']
                    ? (int) $entry['parentId']
                    : null,
                'position' => (int) ($entry['position'] ?? 0),
            ];
        }

        return new MarkdownNoteReorderInput(entries: $entries);
    }
}
