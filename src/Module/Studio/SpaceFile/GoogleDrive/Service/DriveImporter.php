<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceContent\Service\SpaceAttachmentUploader;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function fclose;
use function fopen;
use function fwrite;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A Drive file, filed in the space's media library.
 *
 * **A copy, on purpose.** The rest of the integration copies nothing: a
 * shared folder is a living shelf, and what is removed from it disappears
 * from the space. An attachment on a card is the opposite, a decision taken
 * at a given moment: the brief pinned on a record must stay the one that was
 * discussed, not a link that empties the day the client cleans up their
 * Drive.
 *
 * **And that is what makes it attachable anywhere without inventing
 * anything.** Once in the media library, the file is a document like any
 * other: records already know how to attach one, notes already know how to
 * display one, and the calendar shows the same records. A third kind of
 * attachment, pointing to Google, would have required each of these screens
 * to know about it.
 *
 * The name comes from Google, as for a download: it is the only place it
 * exists, and letting it come from elsewhere would mean writing a file name
 * from what a browser sends.
 */
final readonly class DriveImporter
{
    public function __construct(
        private DriveClient $driveClient,
        private SpaceAttachmentUploader $uploader,
        private LoggerInterface $logger,
    ) {}

    /**
     * Files the file and returns the document, or null.
     *
     * Null when Google does not serve it: a native document has no bytes to
     * download, and a file removed from sharing no longer has any. The caller
     * turns it into a 404 rather than an error in the middle of a screen.
     */
    /**
     * @param string|null $folderId the folder the file must be in: the
     *                              space's own by default, the agency's when
     *                              importing from it
     */
    public function import(
        GoogleServiceAccount $account,
        string $fileId,
        CustomerSpaceInterface $space,
        ?string $folderId = null,
    ): ?DocumentInterface {
        // Only a file from the target folder: the service account reads
        // others, and an id can be guessed or copied.
        $folderId ??= $space->getDriveFolderId();

        if (null === $folderId || !$this->driveClient->contains($account, $folderId, $fileId)) {
            return null;
        }

        $metadata = $this->driveClient->metadata($account, $fileId);
        $upstream = $this->driveClient->download($account, $fileId);

        if (null === $metadata || !$upstream instanceof ResponseInterface) {
            return null;
        }

        $path = $this->streamToFile($upstream);

        if (null === $path) {
            return null;
        }

        try {
            // `test: true`: the file did not arrive through a form, and
            // without it Symfony refuses to move it.
            return $this->uploader->upload(
                new UploadedFile($path, $metadata['name'], $metadata['mimeType'], null, true),
                $space,
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive file could not be filed.', [
                'fileId' => $fileId,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** In chunks: a shared folder holds videos. */
    private function streamToFile(ResponseInterface $upstream): ?string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'aurora-drive-import-');
        $handle = fopen($path, 'wb');

        if (false === $handle) {
            unlink($path);

            return null;
        }

        try {
            foreach ($this->driveClient->stream($upstream) as $chunk) {
                fwrite($handle, $chunk);
            }
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive file could not be read.', ['exception' => $throwable->getMessage()]);
            fclose($handle);
            unlink($path);

            return null;
        }

        fclose($handle);

        return $path;
    }
}
