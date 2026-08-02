<?php

declare(strict_types=1);

namespace Aurora\Core\Validation\Exception;

use InvalidArgumentException;

/**
 * A Manager-side rejection that names the field it belongs to.
 *
 * Managers check what constraints cannot — that the row a foreign id points
 * at still exists, that a move does not create a cycle. Controllers used to
 * catch the resulting InvalidArgumentException and pin it to one field,
 * which is fine while a method has one way to fail and wrong the moment it
 * has two: an editor told "this target does not exist" under the parent
 * picker has no idea what to change.
 *
 * The message is already translated — it is written for a human, not looked
 * up again downstream — unlike constraint messages, which travel as keys.
 */
class FieldException extends InvalidArgumentException
{
    public function __construct(private readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public function getField(): string
    {
        return $this->field;
    }
}
