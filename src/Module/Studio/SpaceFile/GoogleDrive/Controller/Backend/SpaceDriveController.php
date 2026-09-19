<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveArchive;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveClient;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveFileServer;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting\DriveSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function date;
use function mb_trim;
use function preg_match;
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

    /**
     * Ce que Google accepte comme identifiant, et ce qu'une adresse de dossier
     * en contient. Vérifié pour que coller l'adresse entière par erreur donne
     * un refus lisible plutôt qu'une liste vide inexplicable.
     */
    private const string FOLDER_ID = '/^[A-Za-z0-9_-]{10,128}$/';

    public function __construct(
        private readonly DriveSettings $settings,
        private readonly DriveClient $drive,
        private readonly DriveFileServer $files,
        private readonly DriveArchive $archives,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Le dossier que cet espace regarde.
     *
     * L'adresse entière est acceptée et découpée : c'est ce qu'on a sous la
     * main quand on vient de l'ouvrir dans Drive, et exiger l'identifiant nu
     * ferait échouer le geste le plus naturel.
     */
    #[Route('/folder', name: '_folder', methods: [HttpMethodEnum::Post->value])]
    public function setFolder(CustomerSpace $space, Request $request): JsonResponse
    {
        $given = mb_trim((string) ($this->decodeJson($request)['folder'] ?? ''));

        if ('' === $given) {
            $space->setDriveFolderId(null);
            $this->entityManager->flush();

            return $this->jsonSuccess(['folderId' => null]);
        }

        $folderId = $this->folderIdOf($given);

        if (null === $folderId) {
            return $this->jsonFailure('backend.studio.drive.errors.folder_invalid');
        }

        $space->setDriveFolderId($folderId);
        $this->entityManager->flush();

        return $this->jsonSuccess(['folderId' => $folderId]);
    }

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

        if ([] === $files) {
            throw $this->createNotFoundException();
        }

        if ($this->archives->weightOf($files) > DriveArchive::MAX_BYTES) {
            return $this->jsonFailure('backend.studio.drive.errors.archive_too_large');
        }

        $path = $this->archives->zipFor($account, $files);

        return $this->file($path, $this->archiveName($space))->deleteFileAfterSend(true);
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

    /**
     * L'identifiant, qu'on lui donne nu ou dans une adresse.
     */
    private function folderIdOf(string $given): ?string
    {
        if (1 === preg_match('#/folders/([A-Za-z0-9_-]+)#', $given, $match)) {
            return $match[1];
        }

        return 1 === preg_match(self::FOLDER_ID, $given) ? $given : null;
    }
}
