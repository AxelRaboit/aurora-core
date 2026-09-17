<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentUploader;
use Aurora\Module\Studio\SpaceContent\Service\SpaceOrphanedDocumentFinder;
use Aurora\Module\Studio\SpaceNote\Dto\SpaceNoteInputFactoryInterface;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNote;
use Aurora\Module\Studio\SpaceNote\Manager\SpaceNoteManagerInterface;
use Aurora\Module\Studio\SpaceNote\View\SpaceNotesViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
use function is_int;
use function str_starts_with;

/**
 * Les notes d'un espace.
 *
 * **Aucune route publique, et c'est la définition de la fonctionnalité.** Le
 * fil d'une fiche et la discussion sont partagés avec le client ; une note ne
 * l'est pas. Le module n'a pas de contrôleur public à servir, la page du client
 * ne reçoit rien d'elles, et c'est ce qui permet d'y écrire ce qu'on n'écrirait
 * pas ailleurs.
 *
 * Chaque route nomme l'espace et chaque gestionnaire vérifie que ce qu'on lui a
 * donné lui appartient : la note arrive comme sa propre entité par l'URL, donc
 * rien n'empêche une requête fabriquée de désigner la note d'un client sous
 * l'espace d'un autre. `assertOwned` est ce qui l'empêche.
 */
#[Route('/workspace/{id}/notes', name: 'workspace_space_notes', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceNotesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceNoteManagerInterface $notes,
        protected readonly SpaceNoteInputFactoryInterface $inputFactory,
        protected readonly SpaceNotesViewBuilder $viewBuilder,
        protected readonly PayloadValidator $payloadValidator,
        protected readonly SpaceAttachmentUploader $uploader,
        protected readonly DocumentSerializerInterface $documents,
        protected readonly UploadPolicyProvider $uploadPolicies,
        protected readonly DocumentRepository $documentRepository,
        protected readonly SpaceOrphanedDocumentFinder $orphanedDocuments,
    ) {}

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function create(CustomerSpace $space, Request $request): JsonResponse
    {
        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        try {
            $this->notes->create($space, $input);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    #[Route('/{noteId}/update', name: '_update', requirements: ['noteId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function update(
        CustomerSpace $space,
        #[MapEntity(id: 'noteId')]
        SpaceNote $note,
        Request $request,
    ): JsonResponse {
        $this->assertOwned($space, $note->getSpace()->getId());

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);

        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $this->notes->update($note, $input);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    /**
     * Épingler, sans rouvrir la note.
     *
     * Sa propre route plutôt qu'un `update` : marquer « celle-ci compte
     * aujourd'hui » ne devrait pas renvoyer un titre, un corps et une couleur.
     */
    #[Route('/{noteId}/pin', name: '_pin', requirements: ['noteId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function pin(
        CustomerSpace $space,
        #[MapEntity(id: 'noteId')]
        SpaceNote $note,
    ): JsonResponse {
        $this->assertOwned($space, $note->getSpace()->getId());

        $this->notes->togglePinned($note);

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    /**
     * Supprime la note, et rien d'autre.
     *
     * Les images de son corps restent dans la médiathèque : d'autres notes
     * peuvent les porter, et la réponse porte la liste de celles que plus
     * personne n'utilise, pour que l'écran propose de les jeter au lieu de le
     * décider tout seul - la même règle que les pièces jointes d'une fiche.
     */
    #[Route('/{noteId}/delete', name: '_delete', requirements: ['noteId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function delete(
        CustomerSpace $space,
        #[MapEntity(id: 'noteId')]
        SpaceNote $note,
    ): JsonResponse {
        $this->assertOwned($space, $note->getSpace()->getId());

        $documents = $this->documentsOf($note);

        $this->notes->delete($note);

        return $this->jsonSuccess([
            ...$this->viewBuilder->payload($space),
            ...$this->orphanedPayload($space, $documents),
        ]);
    }

    /**
     * Une image posée dans une note.
     *
     * **Elle va dans le dossier de cet espace, en brouillon**, exactement comme
     * un fichier déposé sur une fiche - ce n'est pas l'endroit générique des
     * images d'édition. Une note parle d'un client ; sa capture d'écran est à
     * ce client, pas au mobilier du site. Le brouillon est ce qui la garde hors
     * du catch-all public : elle se lit par la route réservée au personnel, que
     * `DocumentUrlGenerator` renvoie déjà pour un brouillon.
     *
     * Images seulement : l'éditeur affiche ce qu'on lui rend dans un `<img>`,
     * et un PDF se rangerait sans bruit pour s'afficher en image cassée.
     */
    #[Route('/images', name: '_image', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function uploadImage(CustomerSpace $space, Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->jsonFailure('backend.ged.documents.errors.upload_required');
        }

        if (!str_starts_with((string) $file->getMimeType(), 'image/')) {
            return $this->jsonFailure('backend.ged.documents.errors.image_required');
        }

        $refusal = $this->uploadPolicies->forStaffDocuments()->refusalFor($file);

        if ($refusal instanceof UploadRefusalEnum) {
            return $this->jsonFailure(match ($refusal) {
                UploadRefusalEnum::TooLarge => 'backend.ged.documents.errors.upload_too_large',
                UploadRefusalEnum::TypeRefused => 'backend.ged.documents.errors.upload_type_refused',
                UploadRefusalEnum::Broken => 'backend.ged.documents.errors.upload_failed',
            });
        }

        return $this->jsonSuccess([
            'document' => $this->documents->serialize($this->uploader->upload($file, $space)),
        ]);
    }

    /**
     * Les documents que le corps d'une note porte.
     *
     * Relevés avant la suppression, parce qu'après il n'y a plus de blocs à
     * lire. Lus sur l'identifiant que l'éditeur range à côté de l'adresse :
     * sans lui il n'y aurait qu'une URL à reconnaître.
     *
     * @return list<DocumentInterface>
     */
    private function documentsOf(SpaceNote $note): array
    {
        $documents = [];

        foreach ($note->getBody() as $block) {
            $file = $block['data']['file'] ?? null;
            $id = is_array($file) ? ($file['documentId'] ?? null) : null;
            if (!is_int($id)) {
                continue;
            }
            if (isset($documents[$id])) {
                continue;
            }

            $document = $this->documentRepository->find($id);

            if ($document instanceof DocumentInterface) {
                $documents[$id] = $document;
            }
        }

        return array_values($documents);
    }

    /**
     * Ce que plus personne n'utilise, proposé plutôt que jeté.
     *
     * Le même corps que la suppression d'une fiche, et les mêmes deux règles :
     * le registre d'usages décide de ce qui est orphelin, et l'offre n'est
     * faite qu'à quelqu'un qui peut déjà mettre un document à la corbeille -
     * un bouton qui répondrait 403 serait pire que pas de bouton, et accorder
     * le droit au passage serait un privilège entré par la porte de service.
     *
     * @param list<DocumentInterface> $documents
     *
     * @return array{orphanedDocuments: list<array{id: int, title: string, trashPath: string}>}
     */
    private function orphanedPayload(CustomerSpace $space, array $documents): array
    {
        if (!$this->isGranted('ged.documents.delete')) {
            return ['orphanedDocuments' => []];
        }

        $offered = [];

        foreach ($this->orphanedDocuments->among($space, $documents) as $document) {
            $offered[] = $document + [
                'trashPath' => $this->generateUrl('backend_ged_documents_delete', ['id' => $document['id']]),
            ];
        }

        return ['orphanedDocuments' => $offered];
    }

    private function assertOwned(CustomerSpace $space, ?int $ownerId): void
    {
        if ($ownerId !== $space->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
