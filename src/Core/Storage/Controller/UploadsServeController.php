<?php

declare(strict_types=1);

namespace Aurora\Core\Storage\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\Access\UploadAccessDecider;
use Aurora\Core\Storage\Access\UploadAccessEnum;
use Aurora\Core\Storage\Adapter\R2StorageAdapter;
use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\BinaryFileServer;
use Aurora\Core\Storage\Enum\StorageDeliveryModeEnum;
use Aurora\Core\Storage\StorageDeliveryModeProviderInterface;
use Aurora\Core\Storage\StoredFileLocator;
use Aurora\Core\Storage\Workspace\LocalPathAware;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catch-all serve endpoint for everything Aurora stores.
 *
 * Aurora keeps its files outside the document root (see CLAUDE.md §5bis), so
 * every URL of the shape `/uploads/{path}` is intercepted here, the
 * path-traversal guard runs, and the bytes are delivered.
 *
 * **This address never changes.** Not when the file moves to another backend,
 * not when an administrator changes how files are delivered. That is not a
 * convenience: the block editor writes the URL of an image into the body of a
 * publication, so an address that moved with its file would break every page
 * that embedded it. What varies is only what this endpoint answers with.
 *
 * Locally, the bytes are streamed as they always were, through
 * `mod_xsendfile` in production. Remotely, an administrator's choice decides:
 * a redirect to a signed link, a redirect to the public hostname, or a stream
 * through PHP for installations that want their access rules to keep applying.
 *
 * **Auth model**: every request is put to {@see UploadAccessDecider} first,
 * and an area states its own rule by registering a guard. Anonymous is still
 * the answer for an area nobody claims, because these assets are typically
 * embedded on public pages - but it is now an answer rather than an absence
 * of one.
 *
 * That distinction was not academic. Until guards existed this endpoint
 * served anything under the upload directory to anybody at all, which meant a
 * GED document was readable by whoever guessed its path, and the
 * authorisation the contracts module put on its own route could be walked
 * around by asking this one instead.
 *
 * A key the decider marks restricted is served, but only the long way: the
 * bytes come through the application and the response is private. The two
 * redirecting delivery modes are for public assets only - they hand the
 * visitor a link the application no longer sees, and a link that outlives the
 * check that produced it is not a check.
 */
final class UploadsServeController extends AbstractController
{
    /**
     * How long a signed link lives. Long enough for a page full of images to
     * fetch them, short enough that a copied address stops working before it
     * is useful to anyone else.
     */
    private const int SIGNED_URL_TTL = 300;

    public function __construct(
        private readonly BinaryFileServer $binaryFileServer,
        private readonly StoredFileLocator $locator,
        private readonly StorageDeliveryModeProviderInterface $deliveryModeProvider,
        private readonly UploadAccessDecider $accessDecider,
        #[Autowire(param: 'app.upload_dir')]
        private readonly string $uploadRoot,
    ) {}

    #[Route(
        '/uploads/{path}',
        name: 'uploads_serve',
        requirements: ['path' => '[^.][^./].*'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function serve(string $path): Response
    {
        // Router-level guard against `..` segments. The adapters re-check, but
        // rejecting earlier is cheaper.
        if (str_contains($path, '/../') || str_starts_with($path, '../') || str_ends_with($path, '/..')) {
            throw $this->createNotFoundException();
        }

        $access = $this->accessDecider->decide($path);

        // The same 404 a missing file gets. A distinct 403 would confirm that
        // the path names something, which is the one bit of information an id
        // or a reference is guessed in order to obtain.
        if (UploadAccessEnum::Denied === $access) {
            throw $this->createNotFoundException();
        }

        $adapter = $this->locator->locate($path);

        if (!$adapter instanceof StorageAdapterInterface) {
            throw $this->createNotFoundException();
        }

        if ($adapter instanceof LocalPathAware) {
            return $this->serveLocal($path, $access);
        }

        return $this->serveRemote($adapter, $path, $access);
    }

    private function serveLocal(string $path, UploadAccessEnum $access): Response
    {
        $absolute = $this->binaryFileServer->path($this->uploadRoot, $path);

        try {
            if (UploadAccessEnum::Restricted === $access) {
                // `serve()`'s own default: private, an hour. Still offloaded
                // through mod_xsendfile in production, which happens after
                // this check rather than instead of it.
                return $this->binaryFileServer->serve($absolute, $this->uploadRoot);
            }

            return $this->binaryFileServer->servePublic($absolute, $this->uploadRoot);
        } catch (RuntimeException) {
            throw $this->createNotFoundException();
        }
    }

    private function serveRemote(StorageAdapterInterface $adapter, string $path, UploadAccessEnum $access): Response
    {
        $mode = $this->deliveryModeProvider->deliveryMode();

        // A restricted file is never handed over as a link, whatever the
        // administrator configured. Both redirecting modes end the
        // application's involvement: the public hostname permanently, a
        // signed link for as long as it lives. Either one would let a file
        // the visitor may read right now be re-fetched later, by anybody, on
        // an address that no longer asks.
        if ($adapter instanceof R2StorageAdapter && $mode->isRedirect() && UploadAccessEnum::Anonymous === $access) {
            $target = StorageDeliveryModeEnum::PublicUrl === $mode
                ? $adapter->publicUrl($path)
                : $adapter->temporaryUrl($path, self::SIGNED_URL_TTL);

            if (null !== $target) {
                // 302 rather than 301: the destination is either a signed link
                // that expires or a hostname an administrator may change, and
                // neither should be remembered by a browser forever.
                return new RedirectResponse($target);
            }

            // Public delivery asked for, no hostname configured. Falls through
            // to the proxy rather than failing: a missing optional setting
            // should degrade, not take the images down.
        }

        return $this->streamThrough($adapter, $path, $access);
    }

    /**
     * Streams the object through PHP, a chunk at a time.
     *
     * Chunked rather than read whole: this mode exists for installations that
     * want their access rules to keep applying, and those are the same
     * installations most likely to be serving something too big to hold in
     * memory.
     */
    private function streamThrough(StorageAdapterInterface $adapter, string $path, UploadAccessEnum $access): Response
    {
        $stored = $adapter->stat($path);

        $response = new StreamedResponse(static function () use ($adapter, $path): void {
            foreach ($adapter->readStream($path) as $chunk) {
                echo $chunk;
                flush();
            }
        });

        if (UploadAccessEnum::Restricted === $access) {
            // One visitor's copy. A proxy that kept this would be answering
            // the next request itself, with the bytes of a file the decider
            // was never asked about. The session listener's own downgrade
            // lands on the same answer, so nothing opts out of it here.
            $response->setPrivate();
            $response->setMaxAge(3600);
        } else {
            // Same opt-out as BinaryFileServer::servePublic(), for the same
            // reason: without it the session listener rewrites all of this
            // into `max-age=0, must-revalidate, private` at the end of the
            // request, because something upstream read the session. See that
            // method for the measurement.
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
            $response->setPublic();
            $response->setMaxAge(86400);
            $response->headers->addCacheControlDirective('immutable');
        }

        if ($stored instanceof StoredObject) {
            $response->headers->set('Content-Length', (string) $stored->size);

            if (null !== $stored->checksum) {
                $response->setEtag($stored->checksum);
            }
        }

        return $response;
    }
}
