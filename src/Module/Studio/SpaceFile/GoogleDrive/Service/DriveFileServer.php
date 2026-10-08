<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Service;

use Aurora\Core\Storage\BinaryFileServer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function in_array;

/**
 * A Drive file, relayed under an Aurora address.
 *
 * **Two screens ask for it, one place serves it.** The studio and the page a
 * client opens through their access link relay exactly the same stream; the
 * only thing that tells them apart is the check before the call. Written
 * twice, the security header would have ended up existing on one side only.
 *
 * **As a stream and not in memory**: a shared folder holds videos, and
 * loading fifty megabytes into a PHP string to spit them out would bring the
 * server down on the first big file.
 */
final readonly class DriveFileServer
{
    public function __construct(
        private DriveClient $driveClient,
    ) {}

    /**
     * The file, to look at or to take away.
     *
     * **`$download` decides a trip to Google, not only a header.** Google's
     * response to the content does not carry the file name, so a download that
     * does not ask for it again lands on the client's side under the Google
     * id. The preview needs no name: it does not pay for that call.
     *
     * Null when the file is not in the space's folder, or can no longer be
     * reached - removed from sharing, deleted, or service account revoked.
     * From the space's point of view, it is no longer there: it is up to the
     * controller to turn it into a 404 rather than an error in the middle of a
     * page.
     */
    public function serve(GoogleServiceAccount $account, string $folderId, string $fileId, bool $download = false): ?Response
    {
        // Here and not in the callers: two screens relay, and the day one of
        // them forgot to ask, it would serve someone else's Drive.
        if (!$this->driveClient->contains($account, $folderId, $fileId)) {
            return null;
        }

        $upstream = $this->driveClient->download($account, $fileId);

        if (!$upstream instanceof ResponseInterface) {
            return null;
        }

        $headers = $upstream->getHeaders(false);
        $type = $headers['content-type'][0] ?? 'application/octet-stream';

        $response = new StreamedResponse(function () use ($upstream): void {
            foreach ($this->driveClient->stream($upstream) as $chunk) {
                echo $chunk;
                flush();
            }
        });

        $response->headers->set('Content-Type', $type);

        if (isset($headers['content-length'][0])) {
            $response->headers->set('Content-Length', $headers['content-length'][0]);
        }

        // Always. The file comes from a client's Drive but goes out under
        // Aurora's domain: content served with a harmless type and sniffed as
        // a document would run with the reader's session.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Never kept by an intermediary: the file lives with the client, who
        // can remove it from sharing at any time, and a shared cache would
        // still serve it afterwards. The same goes for a revoked access link.
        $response->headers->set('Cache-Control', 'private, no-store');

        // An SVG or an HTML file opened in a tab is script running on Aurora's
        // origin with the viewer's session. Those types become files again,
        // whether a download was asked for or not.
        $forced = in_array($type, BinaryFileServer::EXECUTABLE_INLINE_TYPES, true);

        if (!$download && !$forced) {
            return $response;
        }

        $metadata = $this->driveClient->metadata($account, $fileId);

        if (null === $metadata) {
            // The name is missing, not the file. An attachment without a name
            // is better than a preview that was precisely refused: the browser
            // then falls back on the last segment of the address.
            $response->headers->set('Content-Disposition', ResponseHeaderBag::DISPOSITION_ATTACHMENT);

            return $response;
        }

        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $metadata['name'],
        ));

        return $response;
    }
}
