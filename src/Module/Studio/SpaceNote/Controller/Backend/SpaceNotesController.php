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
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Controller\SpaceOwnershipTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentUploader;
use Aurora\Module\Studio\SpaceContent\Service\SpaceOrphanedDocumentOffer;
use Aurora\Module\Studio\SpaceNote\Craft\Service\CraftClient;
use Aurora\Module\Studio\SpaceNote\Craft\Service\CraftNoteImporter;
use Aurora\Module\Studio\SpaceNote\Dto\SpaceNoteInputFactoryInterface;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNote;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;
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
use function mb_trim;
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
 *
 * **Et `assertVisible` empêche l'autre.** Une note personnelle n'est jamais
 * remontée à quelqu'un d'autre que son auteur, mais rien n'oblige une requête
 * à passer par ce qu'on lui a montré : une route qui reçoit une note par son
 * identifiant doit reposer la question. Elle répond 404 plutôt que 403, comme
 * partout où l'existence est déjà l'information.
 */
#[Route('/workspace/{id}/notes', name: 'workspace_space_notes', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceNotesController extends AbstractController
{
    use SpaceOwnershipTrait;
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
        protected readonly SpaceOrphanedDocumentOffer $orphanedOffer,
        protected readonly CraftClient $craft,
        protected readonly CraftNoteImporter $craftImporter,
    ) {}

    /**
     * Ce que la connexion Craft laisse voir.
     *
     * Une liste courte, et c'est voulu : la connexion ne porte que les
     * documents désignés dans Craft, ce qui est la seule façon d'éviter qu'un
     * jeton posé sur un serveur loué ouvre tout un savoir personnel pour
     * qu'un brief atterrisse dans un espace.
     *
     * `configured` plutôt qu'une liste vide muette : un écran qui ne propose
     * rien doit pouvoir dire si c'est parce que l'intégration est éteinte ou
     * parce que la connexion est vide.
     */
    #[Route('/craft', name: '_craft', methods: [HttpMethodEnum::Get->value])]
    #[IsGranted('studio.spaces.edit')]
    public function craftDocuments(CustomerSpace $space): JsonResponse
    {
        return $this->jsonSuccess([
            'configured' => $this->craft->isConfigured(),
            'documents' => $this->craft->documents(),
        ]);
    }

    /**
     * Un document Craft, déposé dans l'espace.
     *
     * Le titre vient de la liste et non du corps : c'est celui que Craft
     * affiche, et le Markdown rendu commence rarement par lui.
     */
    #[Route('/craft/import', name: '_craft_import', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function importFromCraft(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $documentId = mb_trim((string) ($payload['documentId'] ?? ''));
        $title = mb_trim((string) ($payload['title'] ?? ''));

        if ('' === $documentId || '' === $title) {
            return $this->jsonFailure('backend.studio.craft.errors.document_required');
        }

        try {
            $note = $this->craftImporter->import($space, $documentId, $title);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        if (!$note instanceof SpaceNoteInterface) {
            return $this->jsonFailure('backend.studio.craft.errors.unreachable');
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

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
        $this->assertVisible($note);

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
        $this->assertVisible($note);

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
        $this->assertVisible($note);

        $documents = $this->documentsOf($note);

        $this->notes->delete($note);

        return $this->jsonSuccess([
            ...$this->viewBuilder->payload($space),
            ...$this->orphanedOffer->payload($space, $documents, $this->isGranted('ged.documents.delete')),
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
     * La note personnelle de quelqu'un d'autre n'existe pas.
     *
     * Le dépôt ne la remonte jamais ; ceci est la même règle posée à l'entrée
     * des routes qui reçoivent une note par son identifiant.
     */
    private function assertVisible(SpaceNote $note): void
    {
        $reader = $this->getUser();

        if (!$note->isVisibleTo($reader instanceof CoreUserInterface ? $reader : null)) {
            throw $this->createNotFoundException();
        }
    }
}
