<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Taxonomy\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(TaxonomyTermInputFactoryInterface::class)]
class TaxonomyTermInputFactory implements TaxonomyTermInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): TaxonomyTermInputInterface
    {
        $parentId = (int) ($data['parentId'] ?? 0);

        return new TaxonomyTermInput(
            translations: $this->translations($data['translations'] ?? null),
            parentId: $parentId > 0 ? $parentId : null,
        );
    }

    /**
     * The slug stays null when the editor left it blank — the Manager
     * derives it from the name, which is where the slugger lives.
     *
     * @return array<string, array{name: string, slug: ?string, description: ?string}>
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

            $name = Str::trimOrNull((string) ($payload['name'] ?? ''));
            if (null === $name) {
                continue;
            }

            $translations[(string) $locale] = [
                'name' => $name,
                'slug' => Str::trimOrNull((string) ($payload['slug'] ?? '')),
                'description' => Str::trimOrNull((string) ($payload['description'] ?? '')),
            ];
        }

        return $translations;
    }
}
