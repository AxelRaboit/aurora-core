<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Service;

use Aurora\Module\Editorial\Form\Dto\FormFieldInputFactoryInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Enum\FormTemplateEnum;
use Aurora\Module\Editorial\Form\Manager\FormManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function sprintf;

/**
 * Lays a template's questions down on a form that was just created.
 *
 * Through the manager, field by field, like the builder does: a template
 * question is then an ordinary field - its reference, its position, its
 * validation - and nothing downstream has to know where it came from.
 */
final readonly class FormTemplateApplier
{
    public function __construct(
        private FormManagerInterface $formManager,
        private FormFieldInputFactoryInterface $fieldInputFactory,
        private TranslatorInterface $translator,
    ) {}

    /**
     * The steps' titles, in the author's language.
     *
     * Steps carry one title for every language (the form stores them that
     * way), so they are worded in the language of the person creating it.
     *
     * @return list<array{title: string}>|null
     */
    public function steps(FormTemplateEnum $template): ?array
    {
        $steps = $template->steps();

        if ([] === $steps) {
            return null;
        }

        return array_map(
            fn (string $key): array => ['title' => $this->translator->trans(sprintf('backend.forms.template_steps.%s', $key))],
            $steps,
        );
    }

    /** @param list<string> $locales the languages the questions are worded in */
    public function apply(FormInterface $form, FormTemplateEnum $template, array $locales): void
    {
        foreach ($template->fields() as $spec) {
            $prefix = sprintf('backend.forms.template_fields.%s', $spec['key']);

            $translations = [];
            foreach ($locales as $locale) {
                $translations[$locale] = [
                    'label' => $this->translator->trans($prefix.'.label', locale: $locale),
                    'placeholder' => $spec['placeholder'] ? $this->translator->trans($prefix.'.placeholder', locale: $locale) : null,
                    'options' => array_map(
                        fn (string $option): string => $this->translator->trans(sprintf('%s.options.%s', $prefix, $option), locale: $locale),
                        $spec['options'],
                    ),
                ];
            }

            $this->formManager->createField($form, $this->fieldInputFactory->fromArray([
                'type' => $spec['type']->value,
                'required' => $spec['required'],
                'step' => $spec['step'],
                'translations' => $translations,
            ]));
        }
    }
}
