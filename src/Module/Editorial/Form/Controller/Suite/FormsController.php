<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Controller\Suite;

use Aurora\Core\Contact\Prospect\ProspectDirectoryInterface;
use Aurora\Core\Contact\Prospect\WebsiteContact;
use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Core\Support\TreeReorderParser;
use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Editorial\Form\Dto\FormFieldInputFactoryInterface;
use Aurora\Module\Editorial\Form\Dto\FormFieldInputInterface;
use Aurora\Module\Editorial\Form\Dto\FormInputFactoryInterface;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormSubmission;
use Aurora\Module\Editorial\Form\Entity\FormSubmissionInterface;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Enum\FormTemplateEnum;
use Aurora\Module\Editorial\Form\Manager\FormManagerInterface;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Aurora\Module\Editorial\Form\Serializer\FormSerializerInterface;
use Aurora\Module\Editorial\Form\Service\FormFieldLabeler;
use Aurora\Module\Editorial\Form\Service\FormSubmissionExporter;
use Aurora\Module\Editorial\Form\Service\FormTemplateApplier;
use Aurora\Module\Editorial\Form\View\FormsViewBuilder;
use InvalidArgumentException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suite/editorial/forms', name: 'suite_editorial_forms')]
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
        private readonly FormTemplateApplier $templateApplier,
        private readonly ProspectDirectoryInterface $prospectDirectory,
        private readonly FormFieldLabeler $labeler,
        private readonly PathTemplateGenerator $pathTemplateGenerator,
    ) {}

    /**
     * The list of forms. It used to redirect to the first, and the side menu
     * listed the others one entry each: the menu grew with every form, and
     * nothing anywhere said which were online or received anything.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Editorial/suite/forms/index.html.twig', $this->viewBuilder->listView());
    }

    /**
     * Digits only. `{id}` never matches across a slash, so the submissions
     * sub-routes are safe either way - but the requirement is what keeps a
     * future literal GET here from being swallowed.
     */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function show(Form $form): Response
    {
        return $this->render('@Editorial/suite/forms/show.html.twig', $this->viewBuilder->editorView($form));
    }

    /**
     * A title and a template, and the form exists.
     *
     * **One title for every language.** Asking for it three times before the
     * form even has a question was the first thing that made creating one
     * feel like paperwork; the Settings tab is where each language gets its
     * own wording. A form left without a translation would, on the other hand,
     * be missing from the pages of that language.
     *
     * The full payload the Settings tab sends is still accepted here, for
     * whoever already speaks it.
     */
    #[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.create')]
    public function create(Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);
        $template = FormTemplateEnum::tryFrom((string) ($data['template'] ?? '')) ?? FormTemplateEnum::Blank;
        $locales = $this->localeContext->getActiveLocales();

        if (!isset($data['translations']) && isset($data['title'])) {
            $data['translations'] = array_fill_keys($locales, ['title' => (string) $data['title']]);
        }

        $data['steps'] ??= $this->templateApplier->steps($template);

        return $this->withFormInput($data, function ($input) use ($template, $locales): JsonResponse {
            $form = $this->formManager->create($input);
            $this->templateApplier->apply($form, $template, $locales);

            return $this->jsonSuccess([
                'form' => $this->formSerializer->serialize($form),
                'editPath' => $this->generateUrl('suite_editorial_forms_show', ['id' => $form->getId()]),
            ]);
        });
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('editorial.forms.edit')]
    public function update(Form $form, Request $request): JsonResponse
    {
        return $this->withFormInput($this->decodeJson($request), function ($input) use ($form): JsonResponse {
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

        // The prospect each message already became, when prospects are at
        // hand: the button turns into a link, and nobody creates it twice.
        $canCreateProspect = $this->prospectDirectory->isAvailable();
        $prospectPaths = $canCreateProspect
            ? $this->prospectDirectory->pathsForSources(array_map($this->sourceReferenceOf(...), $result['items']))
            : [];

        return $this->jsonSuccess([
            'submissions' => array_map(
                fn (FormSubmissionInterface $submission): array => [
                    ...$this->formSerializer->serializeSubmission($submission, $locale),
                    'prospectPath' => $prospectPaths[$this->sourceReferenceOf($submission)] ?? null,
                ],
                $result['items'],
            ),
            'canCreateProspect' => $canCreateProspect,
            'prospectCreatePath' => $canCreateProspect
                ? $this->pathTemplateGenerator->generate('suite_editorial_forms_submission_prospect', ['id' => $form->getId(), 'submissionId' => '__id__'])
                : null,
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    /**
     * A message from the website becomes a prospect, its answers the first
     * line of the prospect's history.
     *
     * The name is the first text field, the convention the contact signal
     * already follows; it is usually the person's name, and it is renamed on
     * the sheet when it should be the company's. Answers the address of the
     * prospect's page, which the screen opens.
     */
    #[Route('/{id}/submissions/{submissionId}/prospect', name: '_submission_prospect', requirements: ['id' => '\\d+', 'submissionId' => '\\d+'], methods: [HttpMethodEnum::Post->value])]
    public function createProspect(Form $form, #[MapEntity(id: 'submissionId')] FormSubmission $submission): JsonResponse
    {
        if ($submission->getForm()->getId() !== $form->getId()) {
            return $this->jsonNotFound();
        }

        if (!$this->prospectDirectory->isAvailable()) {
            return $this->jsonForbidden();
        }

        $locale = $this->localeContext->getDefaultLocale();
        $email = $this->labeler->firstAnswerOfType($form, $submission, FormFieldTypeEnum::Email);
        $lines = array_map(
            static fn (array $pair): string => $pair['label'].' : '.$pair['value'],
            $this->labeler->pairs($form, $submission, $locale),
        );

        $path = $this->prospectDirectory->createFromWebsiteContact(new WebsiteContact(
            name: $this->labeler->firstAnswerOfType($form, $submission, FormFieldTypeEnum::Text) ?? $email ?? $this->sourceReferenceOf($submission),
            email: $email,
            phone: $this->labeler->firstAnswerOfType($form, $submission, FormFieldTypeEnum::Tel),
            sourceReference: $this->sourceReferenceOf($submission),
            sourceLabel: $this->labeler->title($form, $locale),
            summary: implode("\n", $lines),
            receivedAt: $submission->getSubmittedAt(),
        ));

        return $this->jsonSuccess(['prospectPath' => $path]);
    }

    /**
     * What a submission is known by outside this module. Its reference, or
     * its id for the rows written before references existed.
     */
    private function sourceReferenceOf(FormSubmissionInterface $submission): string
    {
        return $submission->getReference() ?? 'form-submission-'.$submission->getId();
    }

    #[Route('/{id}/submissions/export', name: '_submissions_export', requirements: ['id' => '\\d+'], methods: [HttpMethodEnum::Get->value])]
    public function exportSubmissions(Form $form): StreamedResponse
    {
        return $this->exporter->toCsv($form, $this->localeContext->getDefaultLocale());
    }

    /** @param callable(FormInputInterface):JsonResponse $save */
    /** @param array<string, mixed> $data */
    private function withFormInput(array $data, callable $save): JsonResponse
    {
        $input = $this->formInputFactory->fromArray($data);

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
