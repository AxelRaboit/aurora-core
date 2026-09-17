<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceContent\Service\SpaceOrphanedDocumentFinder;
use Aurora\Module\Studio\SpaceFile\Entity\SpaceFile;
use Aurora\Module\Studio\SpaceFile\Manager\SpaceFileManagerInterface;
use Aurora\Module\Studio\SpaceFile\Service\SpaceStoredFileResponder;
use Aurora\Module\Studio\SpaceFile\View\SpaceFilesViewBuilder;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_numeric;

/**
 * Les fichiers de l'espace, ceux qui ne sont sur aucune fiche.
 *
 * **Partagés avec le client, comme le reste de l'espace.** La charte, les
 * logos, le brief, un PDF signé : ce qu'on tend à quelqu'un sans l'épingler à
 * une publication. La surface privée du studio, ce sont les notes, et elles
 * n'ont aucune route publique.
 *
 * Chaque route nomme l'espace et vérifie que ce qu'on lui a donné lui
 * appartient : le fichier arrive par son identifiant, donc rien n'empêche une
 * requête fabriquée de désigner celui d'un autre client.
 */
#[Route('/workspace/{id}/files', name: 'workspace_space_files', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
class SpaceFilesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        protected readonly SpaceFileManagerInterface $files,
        protected readonly SpaceFilesViewBuilder $viewBuilder,
        protected readonly DocumentRepository $documents,
        protected readonly SpaceOrphanedDocumentFinder $orphanedDocuments,
        protected readonly SpaceStoredFileResponder $responder,
    ) {}

    /**
     * Un fichier déposé sur l'espace.
     *
     * Le même téléverseur que sur une fiche, donc le même dossier et le même
     * brouillon : la catégorie et le statut sont décidés là-bas, jamais par la
     * requête - un formulaire qui nommerait sa catégorie pourrait déposer dans
     * celle des contrats.
     */
    #[Route('/upload', name: '_upload', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function upload(CustomerSpace $space, Request $request): JsonResponse
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->jsonInvalidInput(['file' => 'backend.studio.space_files.errors.required']);
        }

        try {
            $this->files->uploadAsStudio($space, $file);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    /**
     * Un document déjà dans la médiathèque, rattaché à l'espace.
     *
     * La moitié « sélecteur » : rien n'est téléversé et rien n'est copié. Deux
     * espaces peuvent porter le même fichier, et c'est la ligne que la
     * médiathèque liste.
     */
    #[Route('/attach', name: '_attach', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function attach(CustomerSpace $space, Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $documentId = $payload['documentId'] ?? null;

        if (!is_numeric($documentId)) {
            return $this->jsonInvalidInput(['documentId' => 'backend.studio.space_files.errors.required']);
        }

        $document = $this->documents->find((int) $documentId);

        if (!$document instanceof DocumentInterface) {
            return $this->jsonInvalidInput(['documentId' => 'backend.studio.space_files.errors.unknown']);
        }

        try {
            $this->files->attachAsStudio($space, $document);
        } catch (FieldException $fieldException) {
            return $this->jsonInvalidInput([$fieldException->getField() => $fieldException->getMessage()]);
        }

        return $this->jsonSuccess($this->viewBuilder->payload($space));
    }

    /**
     * Retire le fichier de l'espace, et laisse le document.
     *
     * La réponse porte ce que plus rien n'utilise, pour que l'écran propose la
     * corbeille au lieu d'en décider : le même contrat que les pièces jointes
     * d'une fiche et les images d'une note.
     */
    #[Route('/{fileId}/remove', name: '_remove', requirements: ['fileId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('studio.spaces.edit')]
    public function remove(
        CustomerSpace $space,
        #[MapEntity(id: 'fileId')]
        SpaceFile $file,
    ): JsonResponse {
        $this->assertOwned($space, $file->getSpace()->getId());

        $document = $file->getDocument();

        $this->files->remove($file);

        return $this->jsonSuccess([
            ...$this->viewBuilder->payload($space),
            ...$this->orphanedPayload($space, [$document]),
        ]);
    }

    /**
     * Le fichier lui-même, lu à travers l'espace.
     *
     * Pas par la route de la médiathèque : un fichier déposé ici est un
     * brouillon, que `DocumentUrlGenerator` adresse par `backend_ged_files`,
     * lequel réclame `ged.documents.view`. Quelqu'un qui gère des espaces
     * clients n'a pas forcément ce privilège, et le lui réclamer afficherait
     * une liste de fichiers illisibles sans dire pourquoi. Ce qui ouvre
     * l'espace ouvre ce qu'il y a dedans.
     */
    #[Route(
        '/{fileId}/{variant}',
        name: '_file',
        requirements: ['fileId' => '\d+', 'variant' => 'file|preview'],
        defaults: ['variant' => 'file'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function serve(
        CustomerSpace $space,
        #[MapEntity(id: 'fileId')]
        SpaceFile $file,
        string $variant,
    ): Response {
        $this->assertOwned($space, $file->getSpace()->getId());

        return $this->responder->respond($file->getDocument(), $variant);
    }

    /**
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
