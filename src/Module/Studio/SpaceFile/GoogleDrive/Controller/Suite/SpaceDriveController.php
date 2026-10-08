<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Controller\Suite;

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
 * A space's Drive folder.
 *
 * **Three actions: designate, list, serve.** The folder belongs to the
 * client, who shared it with the service account; the space only names it.
 * Nothing is copied.
 *
 * **Reading requires seeing the space, filing requires editing it.** The tab
 * shows to anyone who sees the space; requiring `edit` to read it gave a tab
 * where every route refused. Only the two imports write, into the media
 * library, and they keep `edit`.
 */
#[Route('/workspace/{id}/drive', name: 'workspace_space_drive', requirements: ['id' => '\d+'])]
#[IsGranted('studio.spaces.view')]
final class SpaceDriveController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly DriveSettings $settings,
        private readonly DriveClient $driveClient,
        private readonly DriveFileServer $driveFileServer,
        private readonly DriveArchive $archives,
        private readonly DriveImporter $importer,
        private readonly DocumentSerializerInterface $documents,
        private readonly DriveLock $lock,
    ) {}

    /**
     * The folder's files.
     *
     * Four states, and the screen must be able to tell them apart: the
     * integration is not switched on, the space has no folder, the folder
     * returns nothing, or it is empty. The last three look alike and are not
     * fixed in the same place - in the space, in Drive, or in the settings.
     */
    #[Route('', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function files(CustomerSpace $space): JsonResponse
    {
        return $this->listing($space, $space->getDriveFolderId());
    }

    /**
     * The agency's folder, read from this space: the same listing, the same
     * lock. Studio only - no public route serves it.
     */
    #[Route('/agency', name: '_agency_list', methods: [HttpMethodEnum::Get->value], priority: 20)]
    public function agencyFiles(CustomerSpace $space): JsonResponse
    {
        return $this->listing($space, $this->settings->agencyFolderId());
    }

    #[Route('/agency/archive', name: '_agency_archive', methods: [HttpMethodEnum::Get->value], priority: 20)]
    public function agencyArchive(CustomerSpace $space): Response
    {
        return $this->archiveOf($space, $this->settings->agencyFolderId(), 'agence');
    }

    #[Route('/agency/{fileId}/import', name: '_agency_import', requirements: ['fileId' => '[A-Za-z0-9_-]+'], methods: [HttpMethodEnum::Post->value], priority: 20)]
    #[IsGranted('studio.spaces.edit')]
    public function agencyImport(CustomerSpace $space, string $fileId): JsonResponse
    {
        return $this->importFrom($space, $fileId, $this->settings->agencyFolderId());
    }

    #[Route('/agency/{fileId}', name: '_agency_file', requirements: ['fileId' => '[A-Za-z0-9_-]+'], methods: [HttpMethodEnum::Get->value], priority: 20)]
    public function agencyServe(CustomerSpace $space, string $fileId, Request $request): Response
    {
        return $this->serveFrom($this->settings->agencyFolderId(), $fileId, $request);
    }

    private function listing(CustomerSpace $space, ?string $folderId): JsonResponse
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;

        // **The lock is checked here and not only on screen.** A hidden tab
        // has never closed an address: without this line, the folder's full
        // list would go to whoever calls the route directly, and the password
        // would only protect a button.
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

        $files = $this->driveClient->files($account, $folderId);

        return $this->jsonSuccess([
            'configured' => true,
            'folderId' => $folderId,
            // An empty list is ambiguous as long as it is unknown whether the
            // call succeeded. `files()` returns `[]` in both cases, so the
            // control call is the account itself: this boolean only says the
            // folder is designated and the integration switched on.
            'reachable' => true,
            'files' => $files,
        ]);
    }

    /**
     * The whole folder, in one go.
     *
     * **`priority` and not the order of writing.** Without it, `/{fileId}`
     * accepts "archive" as an id and answers 404: the most general route
     * would win depending on the position of the methods in this file, which
     * is a dependency a review does not see.
     */
    #[Route('/archive', name: '_archive', methods: [HttpMethodEnum::Get->value], priority: 10)]
    public function archive(CustomerSpace $space): Response
    {
        return $this->archiveOf($space, $space->getDriveFolderId(), null);
    }

    private function archiveOf(CustomerSpace $space, ?string $folderId, ?string $suffix): Response
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;

        if (!$account instanceof GoogleServiceAccount || null === $folderId) {
            throw $this->createNotFoundException();
        }

        $files = $this->driveClient->files($account, $folderId);

        // **A 404 and not a message.** This button is a link: the browser
        // navigates to this address, so a JSON response would be displayed
        // verbatim instead of the page. The screen knows the folder's weight
        // before drawing the button and does not offer it in that case; an
        // address typed by hand does not need an explanation.
        if ([] === $files || $this->archives->weightOf($files) > DriveArchive::MAX_BYTES) {
            throw $this->createNotFoundException();
        }

        $path = $this->archives->zipFor($account, $files);

        $name = $this->archiveName($space);
        if (null !== $suffix) {
            $name = preg_replace('/\.zip$/', '-'.$suffix.'.zip', $name) ?? $name;
        }

        return $this->file($path, $name)->deleteFileAfterSend(true);
    }

    /**
     * A Drive file, filed in the media library.
     *
     * **The only place in this integration that copies.** An attachment on a
     * record is a decision taken at a given moment, not a living shelf: it
     * must stay what was discussed even after a clean-up of the client's
     * Drive. The returned document is then attached like any other, through
     * the routes that already exist.
     */
    #[Route('/{fileId}/import', name: '_import', requirements: ['fileId' => '[A-Za-z0-9_-]+'], methods: [HttpMethodEnum::Post->value], priority: 10)]
    #[IsGranted('studio.spaces.edit')]
    public function import(CustomerSpace $space, string $fileId): JsonResponse
    {
        return $this->importFrom($space, $fileId, $space->getDriveFolderId());
    }

    private function importFrom(CustomerSpace $space, string $fileId, ?string $folderId): JsonResponse
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;

        if (!$account instanceof GoogleServiceAccount || null === $folderId) {
            throw $this->createNotFoundException();
        }

        $document = $this->importer->import($account, $fileId, $space, $folderId);

        if (!$document instanceof DocumentInterface) {
            return $this->jsonFailure('suite.studio.drive.errors.import_failed');
        }

        return $this->jsonSuccess(['document' => $this->documents->serialize($document)]);
    }

    /**
     * The file itself, relayed.
     *
     * **This is why this route exists.** A space's client has no Google
     * account: a Drive address would give them an authentication wall. So the
     * server reads the file with the service account and sends it back under
     * an Aurora address.
     *
     * `?download=1` to take it away rather than look at it. The relay is
     * shared with the client's page: what changes from one screen to the
     * other is the check before it, never the way of serving.
     */
    #[Route('/{fileId}', name: '_file', requirements: ['fileId' => '[A-Za-z0-9_-]+'], methods: [HttpMethodEnum::Get->value])]
    public function serve(CustomerSpace $space, string $fileId, Request $request): Response
    {
        return $this->serveFrom($space->getDriveFolderId(), $fileId, $request);
    }

    private function serveFrom(?string $folderId, string $fileId, Request $request): Response
    {
        $account = $this->settings->isEnabled() ? $this->settings->account() : null;

        if (!$account instanceof GoogleServiceAccount || null === $folderId) {
            throw $this->createNotFoundException();
        }

        $response = $this->driveFileServer->serve($account, $folderId, $fileId, $request->query->getBoolean('download'));

        if (!$response instanceof Response) {
            // Removed from sharing, or deleted. A 404 rather than an error:
            // from this space's point of view, the file is no longer there.
            throw $this->createNotFoundException();
        }

        return $response;
    }

    /**
     * The bundle's name, which carries the space's name.
     *
     * "fichiers.zip" in a downloads folder says nothing about who sent it, and
     * two clients would send two of them.
     */
    private function archiveName(CustomerSpace $space): string
    {
        $name = (string) preg_replace('#[^\w\-]+#u', '-', $space->getName());

        return sprintf('%s-%s.zip', mb_trim($name, '-') ?: 'drive', date('Y-m-d'));
    }
}
