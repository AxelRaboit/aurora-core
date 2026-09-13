<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInputFactoryInterface;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInputFactoryInterface;
use Aurora\Module\Studio\Contract\Duplicate\ContractTemplateDuplicator;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersion;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Exception\PublishedVersionIsImmutableException;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManagerInterface;
use Aurora\Module\Studio\Contract\Serializer\ContractTemplateSerializerInterface;
use Aurora\Module\Studio\Contract\View\ContractTemplatesViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/studio/contract-templates', name: 'backend_studio_contract_templates')]
#[IsGranted('studio.contract_templates.view')]
class ContractTemplatesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly ContractTemplateManagerInterface $templateManager,
        protected readonly ContractTemplateDuplicator $duplicator,
        protected readonly ContractTemplateInputFactoryInterface $templateInputFactory,
        protected readonly ContractTemplateVersionInputFactoryInterface $versionInputFactory,
        protected readonly ContractTemplateSerializerInterface $serializer,
        protected readonly ContractTemplatesViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Studio/backend/contract-templates/index.html.twig', $this->viewBuilder->indexView());
    }

    /**
     * The wording editor, on its own page.
     *
     * Full page rather than a modal, like the post editor: nineteen articles in
     * three languages is not something to write in a box floating over a list.
     */
    #[Route('/{id}/versions/{versionId}', name: '_editor', methods: [HttpMethodEnum::Get->value])]
    public function editor(ContractTemplate $template, int $versionId): Response
    {
        $version = $this->versionOf($template, $versionId);

        return $this->render(
            '@Studio/backend/contract-templates/editor.html.twig',
            $this->viewBuilder->editorView($template, $version),
        );
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.create')]
    public function create(Request $request): JsonResponse
    {
        $input = $this->templateInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $template = $this->templateManager->create($input);

        return $this->jsonSuccess([
            'template' => $this->serializer->serialize($template),
            'templates' => $this->viewBuilder->templates(),
            // The draft is handed back so the page can go straight to the
            // editor: creating a trame and then hunting for its first version
            // are not two things anybody wants to do separately.
            'draftId' => $template->getDraft()?->getId(),
        ]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.edit')]
    public function update(ContractTemplate $template, Request $request): JsonResponse
    {
        $input = $this->templateInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->templateManager->update($template, $input);

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    #[Route('/{id}/archive', name: '_archive', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.edit')]
    public function archive(ContractTemplate $template): JsonResponse
    {
        $this->templateManager->archive($template);

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    #[Route('/{id}/restore', name: '_restore', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.edit')]
    public function restore(ContractTemplate $template): JsonResponse
    {
        $this->templateManager->restore($template);

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.delete')]
    public function delete(ContractTemplate $template): JsonResponse
    {
        try {
            $this->templateManager->delete($template);
        } catch (FieldException $fieldException) {
            // A trame a frozen contract was built from. The screen shows the
            // refusal where it asked the question, and archiving is still
            // there beside it.
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    /**
     * Copies a trame, wording included, into a new one whose first version is
     * a draft.
     *
     * Guarded by `create` rather than by `edit`: duplicating makes a new trame,
     * and somebody allowed to write trames should be able to start from one
     * they may not modify. The copy is theirs to publish.
     */
    #[Route('/{id}/duplicate', name: '_duplicate', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.create')]
    public function duplicate(ContractTemplate $template): JsonResponse
    {
        $copy = $this->duplicator->duplicate($template);

        return $this->jsonSuccess([
            ...$this->viewBuilder->listPayload(),
            'draftId' => $copy->getDraft()?->getId(),
            'editorPath' => $copy->getDraft() instanceof ContractTemplateVersionInterface
                ? $this->generateUrl('backend_studio_contract_templates_editor', [
                    'id' => $copy->getId(),
                    'versionId' => $copy->getDraft()->getId(),
                ])
                : null,
        ]);
    }

    #[Route('/{id}/open-draft', name: '_open_draft', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.edit')]
    public function openDraft(ContractTemplate $template): JsonResponse
    {
        try {
            $draft = $this->templateManager->openDraft($template);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess([
            'draftId' => $draft->getId(),
            'templates' => $this->viewBuilder->templates(),
        ]);
    }

    #[Route('/{id}/versions/{versionId}/save', name: '_save_draft', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.edit')]
    public function saveDraft(ContractTemplate $template, int $versionId, Request $request): JsonResponse
    {
        $version = $this->versionOf($template, $versionId);
        $input = $this->versionInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->templateManager->updateDraft($version, $input);
        } catch (PublishedVersionIsImmutableException) {
            return $this->publishedRefusal();
        }

        return $this->jsonSuccess(['version' => $this->serializer->serializeVersion($version)]);
    }

    #[Route('/{id}/versions/{versionId}/publish', name: '_publish', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.edit')]
    public function publish(ContractTemplate $template, int $versionId): JsonResponse
    {
        $version = $this->versionOf($template, $versionId);

        try {
            $this->templateManager->publish($version);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        } catch (PublishedVersionIsImmutableException) {
            return $this->publishedRefusal();
        }

        return $this->jsonSuccess(['version' => $this->serializer->serializeVersion($version)]);
    }

    #[Route('/{id}/versions/{versionId}/discard', name: '_discard', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contract_templates.delete')]
    public function discard(ContractTemplate $template, int $versionId): JsonResponse
    {
        $version = $this->versionOf($template, $versionId);

        try {
            $this->templateManager->discardDraft($version);
        } catch (PublishedVersionIsImmutableException) {
            return $this->publishedRefusal();
        }

        // Both callers answered at once: the editor navigates to `indexPath`,
        // the list refreshes in place from the payload. One route, because
        // abandoning a draft is the same act from either screen.
        return $this->jsonSuccess([
            'indexPath' => $this->generateUrl('backend_studio_contract_templates'),
            ...$this->viewBuilder->listPayload(),
        ]);
    }

    /**
     * The version, checked against the template that owns it.
     *
     * Looked up through the template rather than by id alone: a version id from
     * another trame would otherwise be edited under this one's permissions and
     * this one's page.
     */
    private function versionOf(ContractTemplate $template, int $versionId): ContractTemplateVersion
    {
        foreach ($template->getVersions() as $version) {
            if ($version->getId() === $versionId && $version instanceof ContractTemplateVersion) {
                return $version;
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * A write that reached a published version.
     *
     * The page should never offer it, so this is the answer to a stale tab
     * rather than to a normal action: reported as a field error so the editor
     * shows the sentence rather than a silent failure.
     */
    private function publishedRefusal(): JsonResponse
    {
        return $this->jsonInvalidInput([
            'version' => $this->translator->trans(
                'backend.studio.contract_templates.errors.already_published',
            ),
        ]);
    }
}
