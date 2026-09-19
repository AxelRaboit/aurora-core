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
 * Un fichier du Drive, relayé sous une adresse d'Aurora.
 *
 * **Deux écrans le demandent, un seul endroit le sert.** Le studio et la page
 * qu'un client ouvre par son lien d'accès relaient exactement le même flux ;
 * la seule chose qui les distingue est le contrôle qui précède l'appel. Écrit
 * deux fois, l'en-tête de sécurité n'aurait fini par exister que d'un côté.
 *
 * **En flux et non en mémoire** : un dossier partagé contient des vidéos, et
 * charger cinquante mégaoctets dans une chaîne PHP pour les recracher ferait
 * tomber le serveur sur le premier gros fichier.
 */
final readonly class DriveFileServer
{
    public function __construct(
        private DriveClient $drive,
    ) {}

    /**
     * Le fichier, à regarder ou à emporter.
     *
     * **`$download` décide d'un aller chez Google, pas seulement d'un
     * en-tête.** Sa réponse au contenu ne porte pas le nom du fichier, donc
     * un téléchargement qui ne le redemande pas atterrit chez le client sous
     * l'identifiant Google. L'aperçu, lui, n'a besoin d'aucun nom : il ne paie
     * pas cet appel.
     *
     * Null quand le fichier n'est plus joignable - retiré du partage,
     * supprimé, ou compte de service révoqué. Du point de vue de l'espace, il
     * n'est plus là : c'est au contrôleur d'en faire un 404 plutôt qu'une
     * erreur au milieu d'une page.
     */
    public function serve(GoogleServiceAccount $account, string $fileId, bool $download = false): ?Response
    {
        $upstream = $this->drive->download($account, $fileId);

        if (!$upstream instanceof ResponseInterface) {
            return null;
        }

        $headers = $upstream->getHeaders(false);
        $type = $headers['content-type'][0] ?? 'application/octet-stream';

        $response = new StreamedResponse(function () use ($upstream): void {
            foreach ($this->drive->stream($upstream) as $chunk) {
                echo $chunk;
                flush();
            }
        });

        $response->headers->set('Content-Type', $type);

        if (isset($headers['content-length'][0])) {
            $response->headers->set('Content-Length', $headers['content-length'][0]);
        }

        // Toujours. Le fichier vient du Drive d'un client mais sort sous le
        // domaine d'Aurora : un contenu servi avec un type inoffensif et
        // reniflé en document s'exécuterait avec la session du lecteur.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Jamais gardé par un intermédiaire : le fichier vit chez le client,
        // qui peut le retirer du partage à tout moment, et un cache partagé le
        // servirait encore après. Un lien d'accès révoqué, de même.
        $response->headers->set('Cache-Control', 'private, no-store');

        // Un SVG ou un HTML ouvert dans un onglet, c'est du script qui tourne
        // sur l'origine d'Aurora avec la session de celui qui regarde. Ces
        // types-là redeviennent des fichiers, qu'on ait demandé ou non.
        $forced = in_array($type, BinaryFileServer::EXECUTABLE_INLINE_TYPES, true);

        if (!$download && !$forced) {
            return $response;
        }

        $metadata = $this->drive->metadata($account, $fileId);

        if (null === $metadata) {
            // Le nom manque, pas le fichier. Une pièce jointe sans nom vaut
            // mieux qu'un aperçu qu'on a justement refusé d'afficher : le
            // navigateur retombe alors sur le dernier segment de l'adresse.
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
