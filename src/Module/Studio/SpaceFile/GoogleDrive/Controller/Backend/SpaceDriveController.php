<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Serializer\DocumentSerializerInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Security\DriveLock;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveArchive;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveClient;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveFileServer;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveImporter;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting\DriveSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function date;
use function mb_trim;
use function preg_replace;
use function sprintf;

/**
 * Le dossier Drive d'un espace.
 *
 * **Trois gestes : désigner, lister, servir.** Le dossier appartient au
 * client, qui l'a partagé avec le compte de service ; l'espace ne fait que le
 * nommer. Rien n'est recopié.
 */
#[Route('/workspace/{id}/drive', name: 'workspace_space_drive', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.edit')]
final class SpaceDriveController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly DriveSettings $settings,
        private readonly DriveClient $drive,
        private readonly DriveFileServer $files,
        private readonly DriveArchive $archives,
        private readonly DriveImporter $importer,
        private readonly DocumentSerializerInterface $documents,
        private readonly DriveLock $lock,
    ) {}

    /**
     * Les fichiers du dossier.
     *
     * Quatre états, et l'écran doit pouvoir les distinguer : l'intégration
     * n'est pas allumée, l'espace n'a pas de dossier, le dossier ne répond
     * rien, ou il est vide. Les trois derniers se ressemblent et ne se
     * réparent pas au même endroit - dans l'espace, dans Drive, ou dans les
     * réglages.
     */
    #[Route('', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function files(CustomerSpace $space): JsonResponse
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;
        $folderId = $space->getDriveFolderId();

        // **La serrure se vérifie ici et pas seulement à l'écran.** Un onglet
        // masqué n'a jamais fermé une adresse : sans cette ligne, la liste
        // complète du dossier partirait à qui appelle la route directement,
        // et le mot de passe ne protégerait qu'un bouton.
        if ($this->lock->isClosedFor($space)) {
            return $this->jsonSuccess([
                'configured' => true,
                'folderId' => $folderId,
                'reachable' => false,
                'locked' => true,
                'files' => [],
            ]);
        }

        if (!$account instanceof GoogleServiceAccount || null === $folderId) {
            return $this->jsonSuccess([
                'configured' => $account instanceof GoogleServiceAccount,
                'folderId' => $folderId,
                'reachable' => false,
                'files' => [],
            ]);
        }

        $files = $this->drive->files($account, $folderId);

        return $this->jsonSuccess([
            'configured' => true,
            'folderId' => $folderId,
            // Une liste vide est ambiguë tant qu'on ne sait pas si l'appel a
            // abouti. `files()` rend `[]` dans les deux cas, donc l'appel
            // témoin est le compte lui-même : ce booléen dit seulement que le
            // dossier est désigné et l'intégration allumée.
            'reachable' => true,
            'files' => $files,
        ]);
    }

    /**
     * Tout le dossier, en une fois.
     *
     * **`priority` et non l'ordre d'écriture.** Sans elle, `/{fileId}` accepte
     * « archive » comme identifiant et répond 404 : la route la plus générale
     * gagnerait selon la position des méthodes dans ce fichier, ce qui est une
     * dépendance qu'une relecture ne voit pas.
     */
    #[Route('/archive', name: '_archive', methods: [HttpMethodEnum::Get->value], priority: 10)]
    public function archive(CustomerSpace $space): Response
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;
        $folderId = $space->getDriveFolderId();

        if (!$account instanceof GoogleServiceAccount || null === $folderId) {
            throw $this->createNotFoundException();
        }

        $files = $this->drive->files($account, $folderId);

        // **Un 404 et non un message.** Ce bouton est un lien : le navigateur
        // navigue vers cette adresse, donc une réponse JSON s'afficherait en
        // toutes lettres à la place de la page. L'écran connaît le poids du
        // dossier avant de dessiner le bouton et ne le propose pas dans ce
        // cas ; une adresse tapée à la main n'a pas à recevoir d'explication.
        if ([] === $files || $this->archives->weightOf($files) > DriveArchive::MAX_BYTES) {
            throw $this->createNotFoundException();
        }

        $path = $this->archives->zipFor($account, $files);

        return $this->file($path, $this->archiveName($space))->deleteFileAfterSend(true);
    }

    /**
     * Un fichier du Drive, rangé dans la médiathèque.
     *
     * **Le seul endroit de cette intégration qui recopie.** Une pièce jointe
     * sur une fiche est une décision prise à un moment, pas une étagère
     * vivante : elle doit rester ce dont on a parlé même après un ménage dans
     * le Drive du client. Le document rendu est ensuite accroché comme
     * n'importe quel autre, par les routes qui existent déjà.
     */
    #[Route('/{fileId}/import', name: '_import', requirements: ['fileId' => '[A-Za-z0-9_-]+'], methods: [HttpMethodEnum::Post->value], priority: 10)]
    public function import(CustomerSpace $space, string $fileId): JsonResponse
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;

        if (!$account instanceof GoogleServiceAccount || null === $space->getDriveFolderId()) {
            throw $this->createNotFoundException();
        }

        $document = $this->importer->import($account, $fileId, $space);

        if (!$document instanceof DocumentInterface) {
            return $this->jsonFailure('backend.studio.drive.errors.import_failed');
        }

        return $this->jsonSuccess(['document' => $this->documents->serialize($document)]);
    }

    /**
     * Le fichier lui-même, relayé.
     *
     * **C'est la raison d'être de cette route.** Le client d'un espace n'a pas
     * de compte Google : une adresse Drive lui donnerait un mur
     * d'authentification. Le serveur lit donc le fichier avec le compte de
     * service et le renvoie sous une adresse d'Aurora.
     *
     * `?download=1` pour l'emporter plutôt que le regarder. Le relais est
     * partagé avec la page du client : ce qui change d'un écran à l'autre est
     * le contrôle qui précède, jamais la façon de servir.
     */
    #[Route('/{fileId}', name: '_file', requirements: ['fileId' => '[A-Za-z0-9_-]+'], methods: [HttpMethodEnum::Get->value])]
    public function serve(CustomerSpace $space, string $fileId, Request $request): Response
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;

        if (!$account instanceof GoogleServiceAccount || null === $space->getDriveFolderId()) {
            throw $this->createNotFoundException();
        }

        $response = $this->files->serve($account, $fileId, $request->query->getBoolean('download'));

        if (!$response instanceof Response) {
            // Retiré du partage, ou supprimé. Un 404 plutôt qu'une erreur : du
            // point de vue de cet espace, le fichier n'est plus là.
            throw $this->createNotFoundException();
        }

        return $response;
    }

    /**
     * Le nom du lot, qui porte celui de l'espace.
     *
     * « fichiers.zip » dans un dossier de téléchargements ne dit rien de qui
     * l'a envoyé, et deux clients en enverraient deux.
     */
    private function archiveName(CustomerSpace $space): string
    {
        $name = (string) preg_replace('#[^\w\-]+#u', '-', $space->getName());

        return sprintf('%s-%s.zip', mb_trim($name, '-') ?: 'drive', date('Y-m-d'));
    }
}
