<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Dto;

use Aurora\Core\Support\Arr;
use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TaxonomyInputFactoryInterface::class)]
class TaxonomyInputFactory implements TaxonomyInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): TaxonomyInputInterface
    {
        return new TaxonomyInput(
            slug: mb_strtolower(Str::trimOrNull((string) ($data['slug'] ?? '')) ?? ''),
            hierarchical: (bool) ($data['hierarchical'] ?? false),
            translations: $this->translations($data['translations'] ?? null),
            postTypeIds: Arr::positiveInts($data['postTypeIds'] ?? null),
        );
    }

    /**
     * A locale with no label is dropped rather than stored blank: the form
     * ships every active locale, and an editor who filled in one of them
     * meant to translate one of them.
     *
     * @return array<string, array{label: string, description: ?string}>
     */
    private function translations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $translations = [];
        foreach ($raw as $locale => $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $label = Str::trimOrNull((string) ($payload['label'] ?? ''));
            if (null === $label) {
                continue;
            }

            $translations[(string) $locale] = [
                'label' => $label,
                'description' => Str::trimOrNull((string) ($payload['description'] ?? '')),
            ];
        }

        return $translations;
    }
}
