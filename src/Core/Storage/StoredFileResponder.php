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
 * Rend un fichier stocké, une fois que l'appelant a dit oui.
 *
 * **Il ne décide de rien.** Qui a le droit de lire, c'est la route qui le dit,
 * et c'est volontairement resté là : la médiathèque lit par son privilège, un
 * espace par le sien, un client par son lien. Un service qui trancherait à
 * leur place ferait de ces règles une seule.
 *
 * Ce qu'il porte est la mécanique, qui elle ne varie pas : trouver
 * l'adaptateur qui détient la clé, servir le fichier local tel quel - donc
 * déchargé par le serveur web en production - et diffuser le distant par
 * morceaux. Quatre contrôleurs en portaient une copie chacun, au caractère
 * près.
 *
 * **Privé, une heure.** C'est la politique de tout ce qui passe par une
 * autorisation : un cache partagé qui garderait la réponse répondrait à la
 * place du décideur, avec les octets d'un fichier qu'on ne lui a pas soumis.
 * Ce qui est public a son propre chemin, `UploadsServeController`, qui choisit
 * l'inverse en connaissance de cause et reste donc à part.
 *
 * Diffusé plutôt que redirigé, pour la raison que donne `GedFilesController` :
 * un lien signé ou un nom d'hôte public survivrait à l'autorisation qui vient
 * d'être accordée.
 */
final readonly class StoredFileResponder
{
    public function __construct(
        private BinaryFileServer $binaryFileServer,
        private StoredFileLocator $locator,
        #[Autowire(param: 'app.upload_dir')]
        private string $uploadRoot,
    ) {}

    /** @param string $key la clé de stockage, telle qu'elle est enregistrée */
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
