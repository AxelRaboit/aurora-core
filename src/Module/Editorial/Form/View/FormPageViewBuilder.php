<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\View;

use Aurora\Core\Frontend\Service\Context;
use Aurora\Module\Configuration\Theme\Service\ThemeContext;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Form\Serializer\FormSerializerInterface;
use Aurora\Module\Editorial\Seo\Service\AlternatesBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Payload for the public form page.
 */
final readonly class FormPageViewBuilder
{
    public function __construct(
        private FormSerializerInterface $formSerializer,
        private AlternatesBuilder $alternatesBuilder,
        private Context $context,
        private ThemeContext $themeContext,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function showView(FormTranslationInterface $translation, string $locale): array
    {
        $form = $translation->getForm();

        return [
            'locale' => $locale,
            'context' => $this->context,
            'themeContext' => $this->themeContext,
            'formTitle' => $translation->getTitle(),
            'formDescription' => $translation->getDescription(),
            'formData' => $this->formSerializer->serializeForReader($form, $locale),
            // Built here rather than in the template: the slug is the
            // translation's, and a template reaching back into the request to
            // find it would break the day the route parameter is renamed.
            'submitPath' => $this->urlGenerator->generate('editorial_form_submit', [
                'locale' => $locale,
                'slug' => $translation->getSlug(),
            ]),
            'alternates' => $this->alternatesBuilder->forForm($form),
        ];
    }
}
