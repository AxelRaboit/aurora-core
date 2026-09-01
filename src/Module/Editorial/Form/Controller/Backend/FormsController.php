<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Support\TreeReorderParser;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Editorial\Form\Dto\FormFieldInputFactoryInterface;
use Aurora\Module\Editorial\Form\Dto\FormFieldInputInterface;
use Aurora\Module\Editorial\Form\Dto\FormInputFactoryInterface;
use Aurora\Module\Editorial\Form\Dto\FormInputInterface;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Manager\FormManagerInterface;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Aurora\Module\Editorial\Form\Serializer\FormSerializerInterface;
use Aurora\Module\Editorial\Form\Service\FormSubmissionExporter;
use Aurora\Module\Editorial\Form\View\FormsViewBuilder;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/backend/editorial/forms', name: 'backend_editorial_forms')]
#[IsGranted('editorial.forms.view')]
class FormsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly FormManagerInterface $formManager,
        private readonly FormSerializerInterface $formSerializer,
        private readonly FormsViewBuilder $viewBuilder,
        private readonly FormInputFactoryInterface $formInputFactory,
        private readonly FormFieldInputFactoryInterface $fieldInputFactory,
        private readonly FormSubmissionRepository $submissionRepository,
        private readonly FormSubmissionExporter $exporter,
        private readonly PayloadValidator $payloadValidator,
        private readonly LocaleContextInterface $localeContext,
    ) {}

    /**
     * One address per form: a bare `/forms` redirects to the first rather than
     * showing what `/forms/3` already shows.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        $first = $this->viewBuilder->firstId();

        if (null !== $first) {
            return $this->redirectToRoute('backend_editorial_forms_show', ['id' => $first]);
        }

        return $this->render('@Editorial/backend/forms/index.html.twig', $this->viewBuilder->indexView());
    }

    /**
     * Digits only. `{id}` never matches across a slash, so the submissions
     * sub-routes are safe either way - but the requirement is what keeps a
     * future literal GET here from being swallowed.
     */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function show(Form $form): Response
    {
        return $this->render('@Editorial/backend/forms/index.html.twig', $this->viewBuilder->indexView($form->getId()));
    }

    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.create')]
    public function create(Request $request): JsonResponse
    {
        return $this->withFormInput($request, function ($input): JsonResponse {
            $form = $this->formManager->create($input);

            return $this->jsonSuccess(['form' => $this->formSerializer->serialize($form)]);
        });
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.edit')]
    public function update(Form $form, Request $request): JsonResponse
    {
        return $this->withFormInput($request, function ($input) use ($form): JsonResponse {
            $this->formManager->update($form, $input);

            return $this->jsonSuccess(['form' => $this->formSerializer->serialize($form)]);
        });
    }

    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.delete')]
    public function delete(Form $form): JsonResponse
    {
        $this->formManager->delete($form);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/fields', name: '_field_create', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.edit')]
    public function createField(Form $form, Request $request): JsonResponse
    {
        return $this->withFieldInput($request, function ($input) use ($form): JsonResponse {
            $this->formManager->createField($form, $input);

            return $this->jsonSuccess(['form' => $this->formSerializer->serialize($form)]);
        });
    }

    #[Route('/{id}/fields/{fieldId}/edit', name: '_field_edit', requirements: ['id' => '\\d+', 'fieldId' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.edit')]
    public function editField(Form $form, int $fieldId, Request $request): JsonResponse
    {
        $field = $form->findFieldById($fieldId);
        if (!$field instanceof FormFieldInterface) {
            return $this->jsonNotFound();
        }

        return $this->withFieldInput($request, function ($input) use ($form, $field): JsonResponse {
            $this->formManager->updateField($field, $input);

            return $this->jsonSuccess(['form' => $this->formSerializer->serialize($form)]);
        });
    }

    #[Route('/{id}/fields/{fieldId}/delete', name: '_field_delete', requirements: ['id' => '\\d+', 'fieldId' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.edit')]
    public function deleteField(Form $form, int $fieldId): JsonResponse
    {
        $field = $form->findFieldById($fieldId);
        if (!$field instanceof FormFieldInterface) {
            return $this->jsonNotFound();
        }

        $this->formManager->deleteField($field);

        return $this->jsonSuccess(['form' => $this->formSerializer->serialize($form)]);
    }

    #[Route('/{id}/fields/reorder', name: '_field_reorder', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.edit')]
    public function reorderFields(Form $form, Request $request): JsonResponse
    {
        // Reuses the tree payload parser even though a form's fields are flat:
        // the browser sends the same `{id, position}` rows, and a second
        // parser would be a second place for them to be read differently.
        $entries = TreeReorderParser::parse($this->decodeJson($request)['entries'] ?? null);

        $ordered = [];
        foreach ($entries as $entry) {
            $ordered[$entry['position']] = $entry['id'];
        }

        ksort($ordered);

        $this->formManager->reorderFields($form, array_values($ordered));

        return $this->jsonSuccess(['form' => $this->formSerializer->serialize($form)]);
    }

    #[Route('/{id}/submissions', name: '_submissions', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Get->value])]
    public function submissions(Form $form, Request $request): JsonResponse
    {
        $pagination = PaginationRequest::fromRequest($request);
        $locale = $this->localeContext->getDefaultLocale();

        $result = $this->submissionRepository->findPaginatedByForm($form, $pagination->page, $pagination->limit);

        return $this->jsonSuccess([
            'submissions' => array_map(
                fn (FormSubmissionInterface $submission): array => $this->formSerializer->serializeSubmission($submission, $locale),
                $result['items'],
            ),
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    #[Route('/{id}/submissions/export', name: '_submissions_export', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Get->value])]
    public function exportSubmissions(Form $form): StreamedResponse
    {
        return $this->exporter->toCsv($form, $this->localeContext->getDefaultLocale());
    }

    /** @param callable(FormInputInterface):JsonResponse $save */
    private function withFormInput(Request $request, callable $save): JsonResponse
    {
        $input = $this->formInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            return $save($input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }
    }

    /** @param callable(FormFieldInputInterface):JsonResponse $save */
    private function withFieldInput(Request $request, callable $save): JsonResponse
    {
        try {
            $input = $this->fieldInputFactory->fromArray($this->decodeJson($request));
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonInvalidInput(['type' => $invalidArgumentException->getMessage()]);
        }

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        return $save($input);
    }
}
