<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\BinaryFileServer;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Access\Manager\ContractAccessLinkManagerInterface;
use Aurora\Module\Studio\Contract\Access\Repository\ContractAccessLinkRepository;
use Aurora\Module\Studio\Contract\Dto\ContractInputFactoryInterface;
use Aurora\Module\Studio\Contract\Dto\ContractInputInterface;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Exception\FrozenContractIsImmutableException;
use Aurora\Module\Studio\Contract\Manager\ContractManagerInterface;
use Aurora\Module\Studio\Contract\Serializer\ContractSerializerInterface;
use Aurora\Module\Studio\Contract\Service\ContractPdfExporter;
use Aurora\Module\Studio\Contract\Service\ContractPdfGenerator;
use Aurora\Module\Studio\Contract\Signature\Dto\ContractSignatureInputFactoryInterface;
use Aurora\Module\Studio\Contract\Signature\Manager\ContractSignatureManagerInterface;
use Aurora\Module\Studio\Contract\Termination\Dto\ContractTerminationInputFactoryInterface;
use Aurora\Module\Studio\Contract\View\ContractsViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/backend/studio/contracts', name: 'backend_studio_contracts')]
#[IsGranted('studio.contracts.view')]
class ContractsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly ContractManagerInterface $contractManager,
        protected readonly ContractInputFactoryInterface $inputFactory,
        protected readonly ContractSerializerInterface $serializer,
        protected readonly ContractsViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly ContractAccessLinkManagerInterface $accessLinks,
        protected readonly ContractAccessLinkRepository $accessLinkRepository,
        protected readonly ContractTerminationInputFactoryInterface $terminationInputFactory,
        protected readonly ContractSignatureManagerInterface $signatures,
        protected readonly ContractSignatureInputFactoryInterface $signatureInputFactory,
        protected readonly ContractPdfGenerator $pdfGenerator,
        protected readonly ContractPdfExporter $pdfExporter,
        protected readonly BinaryFileServer $fileServer,
        protected readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@Studio/backend/contracts/index.html.twig', $this->viewBuilder->indexView());
    }

    /**
     * One contract, with its document if it has one.
     *
     * This is where somebody comes to read what was actually sent, and where
     * the seal is checked - recomputed on the spot rather than read back.
     */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function show(Contract $contract): Response
    {
        return $this->render('@Studio/backend/contracts/show.html.twig', $this->viewBuilder->showView($contract));
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contracts.create')]
    public function create(Request $request): JsonResponse
    {
        return $this->withInput($request, fn (ContractInputInterface $input): JsonResponse => $this->jsonSuccess([
            'contract' => $this->serializer->serialize($this->contractManager->create($input)),
            'contracts' => $this->viewBuilder->contracts(),
        ]));
    }

    #[Route('/{id}/update', name: '_update', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contracts.edit')]
    public function update(Contract $contract, Request $request): JsonResponse
    {
        return $this->withInput($request, function (ContractInputInterface $input) use ($contract): JsonResponse {
            try {
                $this->contractManager->update($contract, $input);
            } catch (FrozenContractIsImmutableException) {
                return $this->frozenRefusal();
            }

            return $this->jsonSuccess($this->viewBuilder->listPayload());
        });
    }

    #[Route('/{id}/delete', name: '_delete', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contracts.delete')]
    public function delete(Contract $contract): JsonResponse
    {
        try {
            $this->contractManager->delete($contract);
        } catch (FrozenContractIsImmutableException) {
            return $this->frozenRefusal();
        } catch (FieldException $fieldException) {
            // The retention refusing, and it names the date. A sealed contract
            // is deletable, but not before the evidence stops being required.
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->listPayload());
    }

    /**
     * Seals the document.
     *
     * The point of no return, and the screen says so before the click. Under
     * `edit` for now; when the link and the mail land, sending will be its own
     * permission because it is the act that reaches somebody outside.
     */
    /**
     * The contract read the way the client will read it, before sealing.
     *
     * Only while it is still in preparation. A sealed contract already has its
     * document, stored and hashed, and the screen that shows it prints those
     * bytes rather than re-rendering: re-rendering would show what today's code
     * produces instead of what was signed.
     */
    #[Route('/{id}/preview', name: '_preview', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    public function preview(Contract $contract): JsonResponse
    {
        if (ContractStatusEnum::Draft !== $contract->getStatus()) {
            return $this->jsonInvalidInput([
                'preview' => $this->translator->trans('backend.studio.contracts.errors.preview_sealed'),
            ]);
        }

        return $this->jsonSuccess($this->contractManager->preview($contract));
    }

    #[Route('/{id}/freeze', name: '_freeze', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contracts.edit')]
    public function freeze(Contract $contract): JsonResponse
    {
        try {
            $this->contractManager->freeze($contract);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        } catch (FrozenContractIsImmutableException) {
            return $this->frozenRefusal();
        }

        return $this->jsonSuccess([
            'contract' => $this->serializer->serialize($contract),
            'contracts' => $this->viewBuilder->contracts(),
            'showPath' => $this->generateUrl('backend_studio_contracts_show', ['id' => $contract->getId()]),
        ]);
    }

    /**
     * Mints an address and mails it.
     *
     * Its own permission, unlike sealing: this is the act that reaches somebody
     * outside the application, and the person allowed to prepare a contract is
     * not necessarily the person allowed to send one.
     */
    #[Route('/{id}/send', name: '_send', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contracts.send')]
    public function send(Contract $contract): JsonResponse
    {
        try {
            $link = $this->accessLinks->send($contract);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess([
            'contract' => $this->serializer->serialize($contract),
            'contracts' => $this->viewBuilder->contracts(),
            'sentTo' => $link->getRecipientEmail(),
        ]);
    }

    /**
     * Records the end of the relationship.
     *
     * Under `edit` rather than `delete`: nothing is destroyed, a fact is
     * written down. And not under `send`, because nothing leaves the building -
     * a customer terminates by writing an email, and this is where that email
     * gets recorded.
     */
    #[Route('/{id}/terminate', name: '_terminate', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contracts.edit')]
    public function terminate(Contract $contract, Request $request): JsonResponse
    {
        $input = $this->terminationInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->contractManager->terminate($contract, $input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess([
            'contract' => $this->serializer->serializeDocument($contract),
            ...$this->viewBuilder->listPayload(),
        ]);
    }

    /**
     * Closes the address without touching the document.
     *
     * Under `send` rather than `delete`: revoking is undoing a send, and
     * whoever may open a door may close it.
     */
    #[Route('/{id}/revoke-link', name: '_revoke_link', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contracts.send')]
    public function revokeLink(Contract $contract): JsonResponse
    {
        $link = $this->accessLinkRepository->findActiveFor($contract);

        if (!$link instanceof ContractAccessLinkInterface) {
            return $this->jsonInvalidInput([
                'status' => $this->translator->trans('backend.studio.contracts.errors.no_active_link'),
            ]);
        }

        $this->accessLinks->revoke($link);

        return $this->jsonSuccess([
            'contract' => $this->serializer->serialize($contract),
            'contracts' => $this->viewBuilder->contracts(),
        ]);
    }

    /**
     * The signed PDF, and only that.
     *
     * A contract with no stored file is a 404 rather than a render: this is the
     * address the document page links to when it says "the signed PDF", and an
     * answer that is not the signed PDF would make the sentence false. Everything
     * else goes through `export`.
     */
    #[Route('/{id}/pdf', name: '_pdf', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    #[IsGranted('studio.contracts.view')]
    public function pdf(Contract $contract): Response
    {
        if (!$contract->hasPdf()) {
            throw $this->createNotFoundException();
        }

        return $this->storedPdf($contract);
    }

    /**
     * The contract on paper, whatever state it is in.
     *
     * One action on the list, three answers, and the order of the checks is the
     * safety: a concluded contract hands back the file that was signed, byte
     * for byte, and everything else is rendered on the spot and stamped as a
     * working copy. A stored file that has gone missing is a 404 here too - the
     * one thing this route must never do is answer a request for the signed
     * document with a fresh render of today's templates.
     */
    #[Route('/{id}/export', name: '_export', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Get->value])]
    #[IsGranted('studio.contracts.view')]
    public function export(Contract $contract): Response
    {
        if (!$this->pdfExporter->isProvisional($contract)) {
            return $this->storedPdf($contract);
        }

        $response = new Response($this->pdfExporter->render($contract));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $this->pdfExporter->filename($contract),
        ));

        // Never cached, anywhere. A draft changes between two clicks, and a
        // browser holding yesterday's copy would be showing a document that no
        // longer exists.
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * The signed file, streamed through the application.
     *
     * Its own route under `/backend`, never the catch-all `/uploads/{path}`:
     * that one serves anything under the upload directory to anybody who is
     * logged in, and a signed contract is not that kind of file. Streamed
     * rather than redirected whatever the storage settings say, because the
     * only way it stays behind this authorisation is if the bytes keep coming
     * through it.
     */
    private function storedPdf(Contract $contract): Response
    {
        if (!$this->pdfGenerator->exists($contract)) {
            // A row that names a file no backend holds. A 404 rather than a
            // 500: the contract exists, its copy does not, and the page that
            // linked here is what needs to say so.
            throw $this->createNotFoundException();
        }

        $response = new StreamedResponse(function () use ($contract): void {
            foreach ($this->pdfGenerator->readStream($contract) as $chunk) {
                echo $chunk;
                flush();
            }
        });

        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('%s.pdf', (string) $contract->getReference()),
        ));
        $response->setPrivate();

        return $response;
    }

    /**
     * The countersignature, which concludes the contract.
     *
     * Its own permission: concluding a contract is not the same act as
     * preparing one, and the person who may draft is not necessarily the person
     * who may commit the company.
     */
    #[Route('/{id}/countersign', name: '_countersign', requirements: ['id' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.contracts.countersign')]
    public function countersign(Contract $contract, Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof CoreUserInterface) {
            throw $this->createAccessDeniedException();
        }

        $input = $this->signatureInputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->signatures->countersign($contract, $input, $user, $request);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess([
            'contract' => $this->serializer->serialize($contract),
            'contracts' => $this->viewBuilder->contracts(),
        ]);
    }

    /** @param callable(ContractInputInterface):JsonResponse $save */
    private function withInput(Request $request, callable $save): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));

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

    private function frozenRefusal(): JsonResponse
    {
        return $this->jsonInvalidInput([
            'status' => $this->translator->trans('backend.studio.contracts.errors.already_frozen'),
        ]);
    }
}
