<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Service;

use Aurora\Module\Notes\Markdown\Enum\NotePropertyTypeEnum;

use function is_array;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;

/**
 * A note's properties, as stored: a list of `{key, type, value}` (09/10/2026).
 *
 * Whatever arrives - a save, an import's front matter - goes through here, so
 * the column only ever holds what the screens know how to show: a known type,
 * a value of that type, a name, at most a reasonable number of them.
 */
final class NoteProperties
{
    public const int MAX_PROPERTIES = 30;

    public const int MAX_KEY_LENGTH = 60;

    public const int MAX_VALUE_LENGTH = 500;

    /** @return list<array{key: string, type: string, value: bool|float|int|string|null}> */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $properties = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $key = is_string($entry['key'] ?? null) ? mb_substr(mb_trim($entry['key']), 0, self::MAX_KEY_LENGTH) : '';
            if ('' === $key) {
                continue;
            }

            if (isset($seen[mb_strtolower($key)])) {
                continue;
            }

            $type = NotePropertyTypeEnum::tryFrom(is_string($entry['type'] ?? null) ? $entry['type'] : '') ?? NotePropertyTypeEnum::Text;
            $seen[mb_strtolower($key)] = true;
            $properties[] = ['key' => $key, 'type' => $type->value, 'value' => self::valueOf($type, $entry['value'] ?? null)];

            if (count($properties) >= self::MAX_PROPERTIES) {
                break;
            }
        }

        return $properties;
    }

    private static function valueOf(NotePropertyTypeEnum $type, mixed $value): bool|float|int|string|null
    {
        return match ($type) {
            NotePropertyTypeEnum::Checkbox => true === $value || 'true' === $value || 1 === $value || '1' === $value,
            NotePropertyTypeEnum::Number => is_numeric($value) ? $value + 0 : null,
            NotePropertyTypeEnum::Person => is_numeric($value) && (int) $value > 0 ? (int) $value : null,
            NotePropertyTypeEnum::Date => is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null,
            NotePropertyTypeEnum::Url => is_string($value) && 1 === preg_match('#^https?://#i', mb_trim($value)) ? mb_substr(mb_trim($value), 0, self::MAX_VALUE_LENGTH) : null,
            default => is_scalar($value) && !is_bool($value) ? mb_substr(mb_trim((string) $value), 0, self::MAX_VALUE_LENGTH) : null,
        };
    }
}
