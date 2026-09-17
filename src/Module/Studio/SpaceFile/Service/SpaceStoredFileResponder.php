<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\Service;

use Aurora\Core\Storage\Adapter\StorageAdapterInterface;
use Aurora\Core\Storage\Adapter\StoredObject;
use Aurora\Core\Storage\BinaryFileServer;
use Aurora\Core\Storage\StoredFileLocator;
use Aurora\Core\Storage\Workspace\LocalPathAware;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rend le fichier d'un document, une fois que l'appelant a dit oui.
 *
 * **Il ne décide de rien.** Qui a le droit de lire, c'est la route qui le dit,
 * et c'est volontairement resté là : un espace lit ses fichiers par son propre
 * privilège, un client par son lien, et un service qui trancherait à leur place
 * ferait de ces deux règles une seule.
 *
 * Ce qu'il porte est la mécanique commune : la variante demandée, l'adaptateur
 * de stockage, le fichier local servi tel quel et le distant diffusé. Cinq
 * contrôleurs en portent déjà une copie chacun ; celui-ci est le premier à
 * s'en passer, et les autres pourront s'y raccrocher.
 *
 * Diffusé plutôt que redirigé, pour la raison que donne `GedFilesController` :
 * un lien signé ou un nom d'hôte public survivrait à l'autorisation qui vient
 * d'être accordée.
 */
final readonly class SpaceStoredFileResponder
{
    public function __construct(
        private BinaryFileServer $binaryFileServer,
        private StoredFileLocator $locator,
        #[Autowire(param: 'app.upload_dir')]
        private string $uploadRoot,
    ) {}

    /** @param 'file'|'preview' $variant */
    public function respond(DocumentInterface $document, string $variant): Response
    {
        $key = 'preview' === $variant
            ? ($document->getVariants()['thumbnail'] ?? $document->getThumbnailPath())
            : $document->getFilePath();

        if (null === $key || '' === $key) {
            throw new NotFoundHttpException();
        }

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
