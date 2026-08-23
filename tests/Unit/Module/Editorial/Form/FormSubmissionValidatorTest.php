<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Form;

use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormField;
use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Service\FormConditionEvaluator;
use Aurora\Module\Editorial\Form\Service\FormSubmissionValidator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A field hidden by its own conditions must not be required, and must not be
 * stored.
 *
 * The reference had no server-side notion of conditions at all: it walked
 * every field and enforced `required` on each. So a required field that only
 * appears for one answer was demanded of every visitor, including the ones who
 * could not see it - the form was unsubmittable for them, permanently, with a
 * validation error pointing at an input that was not on their screen.
 * Conditions are a headline feature of the builder, so that was every form
 * using them.
 */
final class FormSubmissionValidatorTest extends TestCase
{
    public function testDoesNotDemandAFieldTheAnswersHide(): void
    {
        $form = $this->contactForm();

        self::assertSame([], $this->validator()->validate($form, [
            '1' => 'Camille',
            '2' => 'Question',
        ]));
    }

    public function testDemandsTheSameFieldOnceTheAnswersShowIt(): void
    {
        $form = $this->contactForm();

        self::assertSame(
            ['3' => 'frontend.editorial.forms.errors.required'],
            $this->validator()->validate($form, ['1' => 'Camille', '2' => 'Quote']),
        );
    }

    public function testDoesNotStoreAnAnswerToAQuestionThatWasHidden(): void
    {
        $form = $this->contactForm();

        // A hostile client can post anything; a hidden field's value is not
        // an answer the visitor gave.
        $data = $this->validator()->extract($form, [
            '1' => 'Camille',
            '2' => 'Question',
            '3' => 'smuggled',
        ]);

        self::assertSame(['1' => 'Camille', '2' => 'Question'], $data);
    }

    public function testRejectsAChoiceTheFormNeverOffered(): void
    {
        $form = $this->contactForm();

        self::assertSame(
            ['2' => 'frontend.editorial.forms.errors.choice_invalid'],
            $this->validator()->validate($form, ['1' => 'Camille', '2' => 'Anything']),
        );
    }

    public function testAnyLogicNeedsOnlyOneConditionMet(): void
    {
        $form = $this->contactForm();
        $form->findFieldById(3)?->setConditionsLogic(ConditionLogicEnum::Any)
            ->setConditions([
                ['fieldId' => 2, 'value' => 'Quote'],
                ['fieldId' => 2, 'value' => 'Other'],
            ]);

        self::assertArrayHasKey('3', $this->validator()->validate($form, ['1' => 'C', '2' => 'Other']));
        self::assertArrayNotHasKey('3', $this->validator()->validate($form, ['1' => 'C', '2' => 'Question']));
    }

    /** Name (text, required), Subject (select, required), Detail (shown only for "Quote"). */
    private function contactForm(): FormInterface
    {
        $form = new Form();

        $this->field($form, 1, FormFieldTypeEnum::Text, 'Name', required: true);
        $this->field($form, 2, FormFieldTypeEnum::Select, 'Subject', required: true, options: ['Question', 'Quote']);
        $this->field($form, 3, FormFieldTypeEnum::Textarea, 'Detail', required: true, conditions: [
            ['fieldId' => 2, 'value' => 'Quote'],
        ]);

        return $form;
    }

    /**
     * @param list<string>                             $options
     * @param list<array{fieldId: int, value: string}> $conditions
     */
    private function field(
        FormInterface $form,
        int $id,
        FormFieldTypeEnum $type,
        string $label,
        bool $required = false,
        array $options = [],
        array $conditions = [],
    ): FormFieldInterface {
        $field = new FormField()
            ->setType($type)
            ->setRequired($required)
            ->setConditions($conditions);

        new ReflectionProperty(FormField::class, 'id')->setValue($field, $id);

        $field->translate('en')->setLabel($label)->setOptions($options);

        $form->addField($field);

        return $field;
    }

    private function validator(): FormSubmissionValidator
    {
        return new FormSubmissionValidator(new FormConditionEvaluator());
    }
}
