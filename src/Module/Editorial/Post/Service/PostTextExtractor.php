<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Post\Service;

use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\PostType\Entity\PostTypeFieldInterface;

/**
 * Flattens a translation into the one text column search reads. Blocks are
 * walked generically rather than per block type: a search index that only
 * knows the block types that existed when it was written silently stops
 * finding content the day someone adds one.
 */
final readonly class PostTextExtractor
{
    private const array TEXT_FIELD_TYPES = ['text', 'textarea', 'url', 'email'];

    public function extract(PostTranslationInterface $translation): string
    {
        // The title is deliberately absent: the post repository matches it
        // and the slug with their own LIKE, so repeating it here would only
        // widen the column.
        $parts = [
            $translation->getDescription(),
            $translation->getMetaTitle(),
            $translation->getMetaDescription(),
            $translation->getFocusKeyword(),
            // The body lives in the grid since it became the only one. Reading
            // `blocks` alone stopped finding a word of it — and nothing would
            // have said so: the column still holds what it held before the
            // migration, so search kept answering, with answers a version out
            // of date.
            $this->textFromGrid($translation->getGrid()),
            $this->textFromCustomFields($translation),
        ];

        $joined = implode(' ', array_filter(
            array_map(static fn (?string $part): string => $part ?? '', $parts),
            static fn (string $part): bool => '' !== mb_trim($part),
        ));

        return mb_trim(preg_replace('/\s+/', ' ', $joined) ?? '');
    }

    /** @param array<int, array<string, mixed>> $blocks */
    public function textFromBlocks(array $blocks): string
    {
        $collected = [];
        foreach ($blocks as $block) {
            $this->collectStrings($block, $collected);
        }

        return implode(' ', $collected);
    }

    /**
     * What a grid holds, in every zone and at every depth.
     *
     * Captions and alt text are collected beside the blocks: they are words an
     * author wrote for a reader, which is the whole test for whether something
     * belongs in a search index. The video address is not — it is a location,
     * and a reader searching for "youtube" wants pages about it rather than
     * every page carrying a clip.
     *
     * @param array<string, mixed> $grid
     */
    public function textFromGrid(array $grid): string
    {
        $zones = is_array($grid['zones'] ?? null) ? $grid['zones'] : [];
        $parts = [];

        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            if (is_array($zone['blocks'] ?? null)) {
                $parts[] = $this->textFromBlocks($zone['blocks']);
            }

            foreach (['alt', 'caption'] as $field) {
                if (is_string($zone[$field] ?? null) && '' !== $zone[$field]) {
                    $parts[] = $zone[$field];
                }
            }
        }

        return implode(' ', array_filter($parts, static fn (string $part): bool => '' !== mb_trim($part)));
    }

    private function textFromCustomFields(PostTranslationInterface $translation): string
    {
        $definitions = $this->postTypeFields($translation);
        $parts = [];

        foreach ($translation->getCustomFields() as $name => $value) {
            if (!is_string($value)) {
                continue;
            }

            $definition = $definitions[$name] ?? null;

            // A value whose field definition is gone is still text someone
            // wrote; index it rather than drop it.
            if (!$definition instanceof PostTypeFieldInterface) {
                $parts[] = $value;

                continue;
            }

            if (in_array($definition->getType(), self::TEXT_FIELD_TYPES, true)) {
                $parts[] = $value;

                continue;
            }

            if ('select' === $definition->getType()) {
                $parts[] = $this->choiceLabel($definition, $value);
            }
        }

        return implode(' ', $parts);
    }

    /** Indexes what the reader sees for a choice, not the stored value. */
    private function choiceLabel(PostTypeFieldInterface $field, string $value): string
    {
        foreach ($field->getOptions()['choices'] ?? [] as $choice) {
            if (is_array($choice) && ($choice['value'] ?? null) === $value) {
                return (string) ($choice['label'] ?? $value);
            }

            if (is_string($choice) && $choice === $value) {
                return $choice;
            }
        }

        return $value;
    }

    /** @return array<string, PostTypeFieldInterface> */
    private function postTypeFields(PostTranslationInterface $translation): array
    {
        $map = [];
        foreach ($translation->getPost()->getPostType()->getFields() as $field) {
            $map[$field->getName()] = $field;
        }

        return $map;
    }

    /** @param list<string> $output */
    private function collectStrings(mixed $value, array &$output): void
    {
        if (is_string($value)) {
            $plain = mb_trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ('' !== $plain) {
                $output[] = $plain;
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectStrings($item, $output);
            }
        }
    }
}
