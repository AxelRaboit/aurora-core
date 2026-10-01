<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Form\Enum\ConditionLogicEnum;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Enum\FormTemplateEnum;
use Aurora\Module\Editorial\Form\Repository\FormRepository;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Aurora\Module\Editorial\Form\Serializer\FormSerializerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function count;

/**
 * Builds the Twig payloads of the two admin screens: the list of forms, and
 * the page of one form.
 */
final readonly class FormsViewBuilder
{
    public function __construct(
        private FormRepository $formRepository,
        private FormSubmissionRepository $submissionRepository,
        private FormSerializerInterface $formSerializer,
        private LocaleContextInterface $localeContext,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {}

    /**
     * One row per form, with what a list has to answer at a glance: is it
     * online, how long is it, does anybody fill it in.
     *
     * @return array<string, mixed>
     */
    public function listView(): array
    {
        // One grouped query per tally, rather than counting a collection per row.
        $counts = $this->submissionRepository->countByForm();
        $lastAt = $this->submissionRepository->lastSubmittedByForm();

        $rows = [];
        foreach ($this->formRepository->findAllForIndex() as $form) {
            $id = (int) $form->getId();
            $translation = $this->translationFor($form);

            $rows[] = [
                'id' => $id,
                'reference' => $form->getReference(),
                'title' => $translation?->getTitle() ?? ($form->getReference() ?? '#'.$id),
                'description' => $translation?->getDescription(),
                'active' => $form->isActive(),
                'fieldCount' => $form->getFields()->count(),
                'stepCount' => count($form->getSteps() ?? []),
                'submissionCount' => $counts[$id] ?? 0,
                'lastSubmittedAt' => $lastAt[$id] ?? null,
                'updatedAt' => $form->getUpdatedAt()->format(DATE_ATOM),
                'editPath' => $this->urlGenerator->generate('backend_editorial_forms_show', ['id' => $id]),
            ];
        }

        return [
            'forms' => $rows,
            'templates' => array_map(
                static fn (FormTemplateEnum $template): array => [
                    'value' => $template->value,
                    'labelKey' => $template->labelKey(),
                    'descriptionKey' => $template->descriptionKey(),
                    'fieldCount' => count($template->fields()),
                    'stepCount' => count($template->steps()),
                ],
                FormTemplateEnum::cases(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function editorView(FormInterface $form): array
    {
        $locales = $this->localeContext->getActiveLocales();

        $publicPaths = [];
        foreach ($locales as $locale) {
            $slug = $form->getTranslation($locale)?->getSlug();
            if (null !== $slug) {
                $publicPaths[$locale] = $this->urlGenerator->generate('editorial_form', ['locale' => $locale, 'slug' => $slug]);
            }
        }

        return [
            'form' => [
                ...$this->formSerializer->serialize($form),
                'submissionCount' => $this->submissionRepository->countByForm()[(int) $form->getId()] ?? 0,
            ],
            'publicPaths' => $publicPaths,
            'locales' => $locales,
            'fieldTypes' => $this->fieldTypes(),
            'conditionLogics' => $this->conditionLogics(),
        ];
    }

    /** The reader's language first, then whichever exists: a form has no locale-free name. */
    private function translationFor(FormInterface $form): ?FormTranslationInterface
    {
        return $form->getTranslation($this->translator->getLocale())
            ?? ($form->getTranslations()->first() ?: null);
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
