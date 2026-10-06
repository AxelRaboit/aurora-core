<?php

declare(strict_types=1);

namespace Aurora\Core\Storage;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\Workspace\LocalPathAware;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns a stored file, once the caller has said yes.
 *
 * **It decides nothing.** Who may read is said by the route, and it was left
 * there on purpose: the media library reads by its privilege, a space by its
 * own, a client by their link. A service that ruled in their place would
 * merge these rules into one.
 *
 * What it carries is the mechanics, which do not vary: find the adapter that
 * holds the key, serve the local file as is - so offloaded to the web server
 * in production - and stream the remote one in chunks. Four controllers each
 * carried a copy of it, character for character.
 *
 * **Private, one hour.** That is the policy for everything that goes through
 * an authorization: a shared cache that kept the response would answer in
 * place of the decider, with the bytes of a file that was never submitted to
 * it. What is public has its own path, `UploadsServeController`, which
 * deliberately chooses the opposite and therefore stays separate.
 *
 * Streamed rather than redirected, for the reason `GedFilesController` gives:
 * a signed link or a public host name would outlive the authorization that
 * has just been granted.
 */
final readonly class StoredFileResponder
{
    public function __construct(
        private BinaryFileServer $binaryFileServer,
        private StoredFileLocator $locator,
        #[Autowire(param: 'app.upload_dir')]
        private string $uploadRoot,
    ) {}

    /** @param string $key the storage key, as it is recorded */
    public function respond(string $key): Response
    {
        $adapter = $this->locator->locate($key);

        if (!$adapter instanceof StorageAdapterInterface) {
            throw new NotFoundHttpException();
        }

        if ($adapter instanceof LocalPathAware) {
            try {
                return $this->binaryFileServer->serve(
                    $this->binaryFileServer->path($this->uploadRoot, $key),
                    $this->uploadRoot,
                );
            } catch (RuntimeException) {
                throw new NotFoundHttpException();
            }
        }

        return $this->streamThrough($adapter, $key);
    }

    private function streamThrough(StorageAdapterInterface $adapter, string $key): Response
    {
        $stored = $adapter->stat($key);

        $response = new StreamedResponse(static function () use ($adapter, $key): void {
            foreach ($adapter->readStream($key) as $chunk) {
                echo $chunk;
                flush();
            }
        });

        $response->setPrivate();
        $response->setMaxAge(3600);

        if ($stored instanceof StoredObject) {
            $response->headers->set('Content-Length', (string) $stored->size);

            if (null !== $stored->checksum) {
                $response->setEtag($stored->checksum);
            }
        }

        return $response;
    }
}
