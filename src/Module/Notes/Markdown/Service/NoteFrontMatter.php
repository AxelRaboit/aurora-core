<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Markdown\Enum\NotePropertyTypeEnum;
use DateTimeInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;

/**
 * The block at the top of an exported note, and the one an imported note
 * may start with (09/10/2026).
 *
 * Obsidian's dialect, which other tools read too: `tags`, the note's `icon`,
 * then each property as a key of its own (`Statut: Validé`). A person is
 * written by name, which means something in a file; an id would not.
 *
 * On the way in, any YAML block is read: the keys this module knows, and
 * every other one as a property, typed from its value. A note exported from
 * Obsidian keeps its properties arriving here. A block that is not YAML, or
 * not a map, stays in the text, visible rather than lost.
 */
final class NoteFrontMatter
{
    /** Keys that are the note's own, or another tool's bookkeeping: never properties. */
    private const array RESERVED = ['tags', 'tag', 'icon', 'title', 'aliases', 'alias', 'cssclasses', 'cssclass', 'publish', 'permalink'];

    /**
     * @param list<string>                                                              $tags
     * @param list<array{key: string, type: string, value: mixed, label?: string|null}> $properties
     */
    public static function write(array $tags, ?string $icon, array $properties, string $content): string
    {
        $front = [];
        if ([] !== $tags) {
            $front['tags'] = $tags;
        }

        if (null !== $icon && '' !== $icon) {
            $front['icon'] = $icon;
        }

        foreach ($properties as $property) {
            if (in_array(mb_strtolower($property['key']), self::RESERVED, true)) {
                continue;
            }

            $front[$property['key']] = NotePropertyTypeEnum::Person->value === $property['type']
                ? ($property['label'] ?? null)
                : $property['value'];
        }

        if ([] === $front) {
            return $content;
        }

        return "---\n".Yaml::dump($front, 1, 2)."---\n\n".$content;
    }

    /**
     * @return array{tags: list<string>, icon: string|null, properties: list<array{key: string, type: string, value: bool|float|int|string|null}>, content: string}
     */
    public static function read(string $raw): array
    {
        $nothing = ['tags' => [], 'icon' => null, 'properties' => [], 'content' => $raw];
        if (!str_starts_with($raw, "---\n")) {
            return $nothing;
        }

        $end = mb_strpos($raw, "\n---", 4);
        if (false === $end) {
            return $nothing;
        }

        try {
            // Dates as dates: unquoted, YAML would read `2026-10-12` as a number of seconds.
            $front = Yaml::parse(mb_substr($raw, 4, $end - 4), Yaml::PARSE_DATETIME);
        } catch (Throwable) {
            return $nothing;
        }

        if (!is_array($front)) {
            return $nothing;
        }

        $tags = [];
        foreach (['tags', 'tag'] as $key) {
            $value = $front[$key] ?? null;
            $list = is_array($value) ? $value : (is_string($value) ? preg_split('/[\s,]+/', $value) : []);
            foreach ($list ?: [] as $tag) {
                if (is_scalar($tag) && '' !== mb_trim((string) $tag)) {
                    $tags[] = mb_ltrim(mb_trim((string) $tag), '#');
                }
            }
        }

        $properties = [];
        foreach ($front as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }

            if (!is_string($key)) {
                continue;
            }
            if (in_array(mb_strtolower($key), self::RESERVED, true)) {
                continue;
            }
            if (!is_scalar($value) && null !== $value) {
                continue;
            }

            $properties[] = ['key' => $key, 'type' => self::typeOf($value)->value, 'value' => $value];
        }

        return [
            'tags' => array_values(array_unique($tags)),
            'icon' => is_string($front['icon'] ?? null) ? $front['icon'] : null,
            'properties' => NoteProperties::normalize($properties),
            'content' => mb_ltrim(mb_substr($raw, $end + 4), "\n"),
        ];
    }

    private static function typeOf(mixed $value): NotePropertyTypeEnum
    {
        return match (true) {
            is_bool($value) => NotePropertyTypeEnum::Checkbox,
            is_int($value), is_float($value) => NotePropertyTypeEnum::Number,
            is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) => NotePropertyTypeEnum::Date,
            is_string($value) && 1 === preg_match('#^https?://#i', $value) => NotePropertyTypeEnum::Url,
            default => NotePropertyTypeEnum::Text,
        };
    }
}
