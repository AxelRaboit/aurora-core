<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Enum;

/**
 * What a step of a space's board means, when somebody said so.
 *
 * **Optional, and the columns stay free.** A studio names its steps the way
 * it works - « À valider », « Chez Marc », « Prêt » - and nothing here renames
 * them. A role says which of the two stages that count across spaces a column
 * stands for:
 *
 * - **Review** is the step where the client answers. When a board has one, a
 *   card there and awaiting an answer is « chez le client », and only there;
 *   a board without one falls back to every step the client can see.
 * - **Published** is what is out: it ends the card's life on the calendar and
 *   is what a missed publication is measured against.
 *
 * Idea, production and scheduled were roles once and decided nothing: the
 * counts never read them. They were removed in 2.0.x rather than kept as
 * labels a reader would think did something.
 *
 * The values are persisted, so they are part of the schema: add and remove,
 * never rename.
 */
enum SpaceContentColumnRoleEnum: string
{
    case Review = 'review';

    case Published = 'published';

    public function getLabelKey(): string
    {
        return 'suite.studio.space_content.column_roles.'.$this->value;
    }
}
