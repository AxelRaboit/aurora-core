<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Frontend\Controller\LocaleTrait;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Form\Manager\FormManagerInterface;
use Aurora\Module\Editorial\Form\Service\FormSubmissionValidator;
use Aurora\Module\Editorial\Form\View\FormViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FormController extends AbstractController
{
    use LocaleTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly FormManagerInterface $formManager,
        private readonly FormSubmissionValidator $formSubmissionValidator,
        private readonly Context $context,
        private readonly ThemeResolver $themeResolver,
        private readonly FormViewBuilder $viewBuilder,
    ) {}

    #[Route('/{locale}/forms/{slug}', name: 'editorial_form', requirements: ['locale' => '[a-z]{2}'], priority: 7)]
    public function show(string $locale, string $slug, Request $request): Response
    {
        $this->assertActiveLocale($this->context, $locale);
        $request->setLocale($locale);

        $translation = $this->formManager->findActiveTranslation($locale, $slug);
        if (!$translation instanceof FormTranslationInterface) {
            throw $this->createNotFoundException();
        }

        $response = $this->render($this->themeResolver->resolve('editorial/form/index'), $this->viewBuilder->showView($translation, $locale));

        return $this->withI18nHeaders($response, $locale);
    }

    #[Route('/{locale}/forms/{slug}/submit', name: 'editorial_form_submit', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Post->value], priority: 8)]
    public function submit(string $locale, string $slug, Request $request): JsonResponse
    {
        $this->assertActiveLocale($this->context, $locale);
        $request->setLocale($locale);

        $translation = $this->formManager->findActiveTranslation($locale, $slug);
        if (!$translation instanceof FormTranslationInterface) {
            return $this->jsonNotFound();
        }

        $form = $translation->getForm();
        $payload = $request->toArray();

        $errors = $this->formSubmissionValidator->validate($form, $payload);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $submittedData = $this->formSubmissionValidator->extractSubmittedData($form, $payload);
        $ip = $request->getClientIp() ?? '';
        $this->formManager->submit($form, $submittedData, $locale, $ip);

        return $this->jsonSuccess();
    }
}
