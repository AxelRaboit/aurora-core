<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Enum;

/**
 * How a field's several show-conditions combine.
 *
 * An enum rather than the reference's free `VARCHAR(3)` holding the string
 * `'and'`: the column accepted anything three characters long, and a typo
 * would have silently changed which fields a visitor sees.
 */
enum ConditionLogicEnum: string
{
    case All = 'and';
    case Any = 'or';

    public function labelKey(): string
    {
        return sprintf('backend.forms.condition_logic.%s', $this->value);
    }
}
