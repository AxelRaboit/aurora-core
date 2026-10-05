<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Enum;

/**
 * What a step of a space's board means, when somebody said so.
 *
 * **Optional, and the columns stay free.** A studio names its steps the way
 * it works - « À valider », « Chez Marc », « Prêt » - and nothing here renames
 * them. The role only says which of the stages every board shares a column
 * stands for, so the counts across spaces can be computed at all: without it,
 * « published » is a word in six spellings and « missed publication » a
 * question nobody can answer.
 *
 * The values are persisted, so they are part of the schema: add and remove,
 * never rename.
 */
enum SpaceContentColumnRoleEnum: string
{
    case Idea = 'idea';

    case Production = 'production';

    case Review = 'review';

    case Scheduled = 'scheduled';

    case Published = 'published';

    public function getLabelKey(): string
    {
        return 'suite.studio.space_content.column_roles.'.$this->value;
    }
}
