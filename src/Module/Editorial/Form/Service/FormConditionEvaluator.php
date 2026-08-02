<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Service;

use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;

/**
 * Which fields a given set of answers actually shows.
 *
 * The browser hides a field whose conditions are unmet; the server has to
 * reach the same conclusion, and it is the server's answer that counts. The
 * reference had no server-side equivalent at all, so a required field hidden
 * by its own conditions was still validated as required — meaning any visitor
 * who did not meet the condition could never submit the form. Conditions are
 * a headline feature of the builder, so that was every form using them.
 */
final readonly class FormConditionEvaluator
{
    /**
     * @param array<array-key, mixed> $answers keyed by field id
     *
     * @return list<FormFieldInterface> the fields this submission should carry
     */
    public function visibleFields(FormInterface $form, array $answers): array
    {
        $visible = [];
        foreach ($form->getFields() as $field) {
            if ($this->isVisible($field, $answers)) {
                $visible[] = $field;
            }
        }

        return $visible;
    }

    /**
     * Answers arrive keyed by field id. The keys are `array-key`, not
     * `string`: they come from JSON as `"3"`, and PHP turns a numeric string
     * key into the integer 3 the moment it lands in an array. Claiming
     * `array<string, …>` describes something PHP cannot hold — and reading
     * such an array by a string key is a lookup that can only ever miss.
     *
     * @param array<array-key, mixed> $answers
     */
    public function isVisible(FormFieldInterface $field, array $answers): bool
    {
        $conditions = $field->getConditions();
        if ([] === $conditions) {
            return true;
        }

        $met = 0;
        foreach ($conditions as $condition) {
            if ($this->matches($answers[$condition['fieldId']] ?? null, $condition['value'])) {
                ++$met;
            }
        }

        return ConditionLogicEnum::Any === $field->getConditionsLogic()
            ? $met > 0
            : $met === count($conditions);
    }

    /**
     * A checkbox answers with a list, everything else with a scalar. Both are
     * compared as strings, because that is what a browser sends and what the
     * builder stored.
     */
    private function matches(mixed $answer, string $expected): bool
    {
        if (is_array($answer)) {
            return in_array($expected, array_map(strval(...), $answer), true);
        }

        return null !== $answer && (string) $answer === $expected;
    }
}
