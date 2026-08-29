<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TagMergeInputFactoryInterface::class)]
class TagMergeInputFactory implements TagMergeInputFactoryInterface
{
    public function fromArray(array $data): TagMergeInput
    {
        return new TagMergeInput(
            sourceTags: $this->stringList($data['sourceTags'] ?? null),
            targetTag: Str::trimFromArray($data, 'targetTag'),
        );
    }

    /** @return list<string> */
    private function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = mb_trim($value);
            if ('' === $trimmed) {
                continue;
            }

            $tags[] = $trimmed;
        }

        return array_values(array_unique($tags));
    }
}
