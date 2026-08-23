<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Form\Manager\FormManagerInterface;
use Aurora\Module\Editorial\Form\Service\FormSubmissionValidator;
use Aurora\Module\Editorial\Form\View\FormPageViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public form page and its submit endpoint.
 *
 * `/forms/` is a fixed segment, so these do not compete with the post and
 * term routes for `/{locale}/{a}/{b}` - but they still need to be tried
 * first, since `/{locale}/forms` would otherwise read as a post type archive.
 */
class FormController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly FormManagerInterface $formManager,
        private readonly FormSubmissionValidator $submissionValidator,
        private readonly FormPageViewBuilder $viewBuilder,
        private readonly ThemeResolver $themeResolver,
        private readonly Context $context,
        private readonly RateLimiterFactoryInterface $formSubmissionLimiter,
    ) {}

    #[Route(
        '/{locale}/forms/{slug}',
        name: 'editorial_form',
        requirements: ['locale' => '[a-z]{2}'],
        methods: [HttpMethodEnum::Get->value],
        priority: 11,
    )]
    public function show(string $locale, string $slug, Request $request): Response
    {
        $translation = $this->activeTranslation($locale, $slug);
        $request->setLocale($locale);

        $response = $this->render(
            $this->themeResolver->resolve('editorial/form/index'),
            $this->viewBuilder->showView($translation, $locale),
        );
        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    #[Route(
        '/{locale}/forms/{slug}',
        name: 'editorial_form_submit',
        requirements: ['locale' => '[a-z]{2}'],
        methods: [HttpMethodEnum::Post->value],
        priority: 11,
    )]
    public function submit(string $locale, string $slug, Request $request): JsonResponse
    {
        $translation = $this->activeTranslation($locale, $slug);
        $form = $translation->getForm();

        // Rate limited before validation, not after: the point is to stop the
        // work, and validating a hundred payloads a second is most of it.
        if (!$this->formSubmissionLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->jsonFailure(
                'frontend.editorial.forms.errors.too_many',
                HttpStatusEnum::TooManyRequests->value,
            );
        }

        $answers = $this->payload($request);

        $errors = $this->submissionValidator->validate($form, $answers);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->formManager->submit(
            $form,
            $this->submissionValidator->extract($form, $answers),
            $locale,
            $request->getClientIp(),
        );

        return $this->jsonSuccess();
    }

    private function activeTranslation(string $locale, string $slug): FormTranslationInterface
    {
        if (!$this->context->isLocaleActive($locale)) {
            throw $this->createNotFoundException();
        }

        $translation = $this->formManager->findActiveTranslation($locale, $slug);
        if (!$translation instanceof FormTranslationInterface) {
            // An inactive form 404s rather than saying "closed": it is a draft
            // the site has not published, and its existence is not public.
            throw $this->createNotFoundException();
        }

        return $translation;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if (!str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')) {
            return $request->request->all();
        }

        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
