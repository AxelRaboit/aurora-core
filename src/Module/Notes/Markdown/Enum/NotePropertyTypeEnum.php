<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Enum;

/**
 * What a note property holds (09/10/2026), as in Obsidian's and Notion's
 * properties: the line at the top of a note that says its status, its date,
 * who it is for. The table view of a folder sorts and filters by them.
 */
enum NotePropertyTypeEnum: string
{
    case Text = 'text';

    case Number = 'number';

    case Date = 'date';

    case Checkbox = 'checkbox';

    case Url = 'url';

    /** A short state shown as a coloured pill: « À faire », « Validé »… */
    case Status = 'status';

    /** A person of the suite, kept by id. */
    case Person = 'person';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
