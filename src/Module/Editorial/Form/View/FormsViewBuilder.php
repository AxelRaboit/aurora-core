<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Repository\FormRepository;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Aurora\Module\Editorial\Form\Serializer\FormSerializerInterface;

/**
 * Builds the Twig payload consumed by the admin forms screen.
 */
final readonly class FormsViewBuilder
{
    public function __construct(
        private FormRepository $formRepository,
        private FormSubmissionRepository $submissionRepository,
        private FormSerializerInterface $formSerializer,
        private LocaleContextInterface $localeContext,
    ) {}

    /** @return array<string, mixed> */
    /** The form a bare `/forms` should send the reader to, or null when there is none. */
    public function firstId(): ?int
    {
        $forms = $this->formRepository->findAllForIndex();

        return [] === $forms ? null : $forms[0]->getId();
    }

    /** @param ?int $activeId the form the address names */
    public function indexView(?int $activeId = null): array
    {
        // One grouped query for every form's tally, rather than counting a
        // collection per row.
        $counts = $this->submissionRepository->countByForm();

        $forms = [];
        foreach ($this->formRepository->findAllForIndex() as $form) {
            $forms[] = [
                ...$this->formSerializer->serialize($form),
                'submissionCount' => $counts[(int) $form->getId()] ?? 0,
            ];
        }

        return [
            'activeId' => $activeId,
            'forms' => $forms,
            'locales' => $this->localeContext->getActiveLocales(),
            'fieldTypes' => $this->fieldTypes(),
            'conditionLogics' => $this->conditionLogics(),
        ];
    }

    /** @return list<array{value: string, labelKey: string, hasOptions: bool}> */
    private function fieldTypes(): array
    {
        return array_map(
            static fn (FormFieldTypeEnum $case): array => [
                'value' => $case->value,
                'labelKey' => $case->labelKey(),
                'hasOptions' => $case->hasOptions(),
            ],
            FormFieldTypeEnum::cases(),
        );
    }

    /** @return list<array{value: string, labelKey: string}> */
    private function conditionLogics(): array
    {
        return array_map(
            static fn (ConditionLogicEnum $case): array => [
                'value' => $case->value,
                'labelKey' => $case->labelKey(),
            ],
            ConditionLogicEnum::cases(),
        );
    }
}
