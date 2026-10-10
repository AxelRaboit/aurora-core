<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Pipeline\Enum;

/**
 * What a stage of the prospect pipeline means, beyond its name.
 *
 * Most stages mean nothing to the code: "Contacted" or "Proposal sent" are
 * words somebody chose, and renaming them changes nothing. Two outcomes do
 * carry a rule, and the rule follows the role rather than the name:
 *
 * - **Won** is where a prospect becomes a client. Dropping a card there opens
 *   the conversion, and converting a prospect from anywhere files it there.
 * - **Lost** is where a prospect stops being followed: its follow-up date is
 *   cleared, and the reason it was lost is asked for.
 *
 * At most one stage holds each role; the Manager enforces it.
 *
 * The values are persisted: add and remove, never rename.
 */
enum PipelineStageRoleEnum: string
{
    case Won = 'won';

    case Lost = 'lost';

    public function getLabelKey(): string
    {
        return 'suite.studio.pipeline.stage_roles.'.$this->value;
    }
}
