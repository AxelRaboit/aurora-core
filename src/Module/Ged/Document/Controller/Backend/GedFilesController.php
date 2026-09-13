<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\BinaryFileServer;
use Aurora\Core\Storage\Enum\StorageAreaEnum;
use Aurora\Core\Storage\StoredFileLocator;
use Aurora\Core\Storage\Workspace\LocalPathAware;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function str_contains;
use function str_starts_with;

/**
 * The GED's own serve endpoint, for the files the public one withholds.
 *
 * `/uploads/{path}` answers for published documents and refuses everything
 * else, which is right for the public and useless for the screen that has to
 * show a draft's thumbnail while somebody decides whether to publish it. So
 * the module defines the more specific route CLAUDE.md §5bis prescribes, the
 * way the contracts module already does.
 *
 * **Why a route and not a privilege check on the catch-all.** The admin
 * firewall is `^/(backend|dev)`. A request to `/uploads/…` is handled by the
 * front firewall, in a different session context, so no backend identity
 * exists there to test - a check placed on the catch-all would refuse staff
 * exactly as it refuses strangers. Being under `/backend` is not decoration
 * here, it is the only place the question can be asked at all.
 *
 * **By key, not by id.** One route then covers a document's own file, its
 * rendered still, each responsive variant and the snapshot every previous
 * version points at, without the caller having to say which kind it holds.
 * `DocumentUrlGenerator` swaps this route in for the catch-all and changes
 * nothing else, so no consumer learns that a second address exists.
 */
#[Route('/backend/ged/files', name: 'backend_ged_files')]
#[IsGranted('ged.documents.view')]
final class GedFilesController extends AbstractController
{
    public function __construct(
        private readonly BinaryFileServer $binaryFileServer,
        private readonly StoredFileLocator $locator,
        #[Autowire(param: 'app.upload_dir')]
        private readonly string $uploadRoot,
    ) {}

    #[Route(
        '/{path}',
        name: '',
        requirements: ['path' => '[^.][^./].*'],
        methods: [HttpMethodEnum::Get->value],
    )]
    public function serve(string $path): Response
    {
        if (str_contains($path, '..')) {
            throw $this->createNotFoundException();
        }

        // Confined to the GED's own area. Without this line the privilege
        // that opens the document library would also open `contracts/`, and
        // the whole point of that area having its own gated route is that
        // reading a signed contract asks a different question.
        if (!str_starts_with($path, StorageAreaEnum::Ged->value.'/')) {
            throw $this->createNotFoundException();
        }

        $adapter = $this->locator->locate($path);

        if (!$adapter instanceof StorageAdapterInterface) {
            throw $this->createNotFoundException();
        }

        if ($adapter instanceof LocalPathAware) {
            try {
                // `serve()`'s own default: private, an hour. Offloaded through
                // mod_xsendfile in production, which happens after this
                // authorisation rather than instead of it.
                return $this->binaryFileServer->serve(
                    $this->binaryFileServer->path($this->uploadRoot, $path),
                    $this->uploadRoot,
                );
            } catch (RuntimeException) {
                throw $this->createNotFoundException();
            }
        }

        return $this->streamThrough($adapter, $path);
    }

    /**
     * Streamed rather than redirected, whatever the delivery settings say.
     * A signed link or a public hostname would outlive this authorisation,
     * and a withheld file that can be re-fetched later by anybody has not
     * been withheld.
     */
    private function streamThrough(StorageAdapterInterface $adapter, string $path): Response
    {
        $stored = $adapter->stat($path);

        $response = new StreamedResponse(static function () use ($adapter, $path): void {
            foreach ($adapter->readStream($path) as $chunk) {
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
