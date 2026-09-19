<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\DriveClient;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Service\GoogleServiceAccount;
use Aurora\Module\Studio\SpaceFile\GoogleDrive\Setting\DriveSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function mb_trim;
use function preg_match;

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
     * Le fichier lui-même, relayé.
     *
     * **C'est la raison d'être de cette route.** Le client d'un espace n'a pas
     * de compte Google : une adresse Drive lui donnerait un mur
     * d'authentification. Le serveur lit donc le fichier avec le compte de
     * service et le renvoie sous une adresse d'Aurora.
     *
     * En flux et non en mémoire : un dossier partagé contient des vidéos, et
     * charger cinquante mégaoctets dans une chaîne PHP pour les recracher
     * ferait tomber le serveur sur le premier gros fichier.
     */
    #[Route('/{fileId}', name: '_file', requirements: ['fileId' => '[A-Za-z0-9_-]+'], methods: [HttpMethodEnum::Get->value])]
    public function serve(CustomerSpace $space, string $fileId): Response
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;

        if (!$account instanceof GoogleServiceAccount || null === $space->getDriveFolderId()) {
            throw $this->createNotFoundException();
        }

        $upstream = $this->drive->download($account, $fileId);

        if (!$upstream instanceof ResponseInterface) {
            // Retiré du partage, ou supprimé. Un 404 plutôt qu'une erreur : du
            // point de vue de cet espace, le fichier n'est plus là.
            throw $this->createNotFoundException();
        }

        $headers = $upstream->getHeaders(false);

        $response = new StreamedResponse(function () use ($upstream): void {
            foreach ($this->drive->stream($upstream) as $chunk) {
                echo $chunk;
                flush();
            }
        });

        $response->headers->set('Content-Type', $headers['content-type'][0] ?? 'application/octet-stream');

        if (isset($headers['content-length'][0])) {
            $response->headers->set('Content-Length', $headers['content-length'][0]);
        }

        // Jamais mis en cache par un intermédiaire : le fichier vit chez le
        // client, qui peut le retirer du partage à tout moment, et un cache
        // partagé le servirait encore après.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
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
