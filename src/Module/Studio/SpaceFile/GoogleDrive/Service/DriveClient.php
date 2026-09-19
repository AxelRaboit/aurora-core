<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceFile\GoogleDrive\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function array_chunk;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function usort;

/**
 * Lit le dossier Drive qu'un client a partagé avec le compte de service.
 *
 * **Rien n'est recopié.** Le Drive reste la source : Aurora lit le fichier
 * chez Google au moment où quelqu'un le demande et le sert sous sa propre
 * adresse. Le client de l'espace, qui n'a pas de compte Google, voit donc le
 * fichier sans jamais parler à Google ; et retirer le fichier du dossier
 * partagé le retire de l'espace, ce qui est le comportement qu'on attend d'un
 * dossier partagé plutôt que d'une copie qui vieillit.
 *
 * **Lecture seule, et dite à Google.** Le jeton est demandé pour la portée
 * `drive.readonly` : même si quelqu'un se trompait plus tard en écrivant une
 * requête d'écriture, Google la refuserait. Une intégration qui ne peut pas
 * écrire est une intégration qui ne peut pas casser le Drive d'un client.
 *
 * **Le jeton est mis en cache.** Google en donne un pour une heure ; le
 * redemander à chaque affichage ajouterait un aller-retour à chaque page pour
 * rien. Gardé cinquante minutes, ce qui laisse dix minutes de marge avant
 * l'expiration réelle - une horloge qui dérive de deux minutes ne doit pas
 * produire un refus au milieu d'une consultation.
 *
 * Les échecs deviennent une liste vide ou un null plutôt qu'une exception :
 * un dossier qu'on ne joint pas est un écran qui ne propose rien, pas une
 * erreur 500 au milieu d'un espace client.
 */
final readonly class DriveClient
{
    private const string SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    private const string FILES_URI = 'https://www.googleapis.com/drive/v3/files';

    private const int TIMEOUT_SECONDS = 15;

    /** Cinquante minutes sur les soixante que Google accorde. */
    private const int TOKEN_TTL_SECONDS = 3000;

    /**
     * Ce qu'un dossier peut légitimement porter à l'écran. Au-delà, ce n'est
     * plus une liste qu'on parcourt et le dossier partagé était trop large.
     */
    private const int MAX_FILES = 200;

    /**
     * Jusqu'où on descend. Cinq étages couvrent tout rangement raisonnable, et
     * la borne existe surtout parce qu'un raccourci circulaire dans un Drive
     * ferait tourner la descente sans fin.
     */
    private const int MAX_DEPTH = 5;

    /**
     * Combien de dossiers tiennent dans une même requête. La clause `q` a une
     * longueur maximale, et quarante parents y tiennent largement.
     */
    private const int PARENTS_PER_QUERY = 40;

    private const string FOLDER_MIME = 'application/vnd.google-apps.folder';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * Tout ce que le dossier contient, sous-dossiers compris.
     *
     * **Un appel par étage, et non par dossier.** Google accepte plusieurs
     * parents dans la même requête : une arborescence de trois niveaux coûte
     * donc trois appels quel que soit le nombre de dossiers qu'elle porte.
     * Descendre dossier par dossier aurait fait une requête chacun, et une
     * page qui attend trente allers-retours n'est plus une page.
     *
     * **Le chemin voyage avec le fichier.** Une liste plate de quarante
     * fichiers sans dire d'où ils viennent serait moins lisible que l'arbre
     * qu'elle remplace ; `path` porte donc « Contrats/2026 », vide à la
     * racine, et l'écran s'en sert pour situer.
     *
     * Deux bornes. La profondeur, parce qu'un raccourci circulaire dans un
     * Drive ferait tourner cette descente sans fin. Et le nombre de fichiers,
     * parce qu'au-delà ce n'est plus une liste qu'on parcourt des yeux - le
     * dossier partagé était trop large, et c'est dans Drive que ça se règle.
     *
     * @return list<array{id: string, name: string, path: string, mimeType: string, size: int|null, modifiedAt: string|null, thumbnail: string|null}>
     */
    public function files(GoogleServiceAccount $account, string $folderId): array
    {
        $files = [];
        $level = [$folderId => ''];
        $seen = [$folderId => true];

        for ($depth = 0; $depth < self::MAX_DEPTH && [] !== $level; ++$depth) {
            $next = [];

            foreach (array_chunk($level, self::PARENTS_PER_QUERY, preserve_keys: true) as $chunk) {
                foreach ($this->children($account, array_keys($chunk)) as $row) {
                    $parentPath = $this->parentPathOf($row, $chunk);

                    if (self::FOLDER_MIME === $row['mimeType']) {
                        // Un raccourci peut ramener un dossier déjà vu, et un
                        // dossier vu deux fois est une boucle.
                        if (!isset($seen[$row['id']])) {
                            $seen[$row['id']] = true;
                            $next[$row['id']] = '' === $parentPath ? $row['name'] : $parentPath.'/'.$row['name'];
                        }

                        continue;
                    }

                    unset($row['parents']);
                    $files[] = [...$row, 'path' => $parentPath];

                    if (self::MAX_FILES === count($files)) {
                        return $this->newestFirst($files);
                    }
                }
            }

            $level = $next;
        }

        return $this->newestFirst($files);
    }

    /**
     * Les enfants directs d'un lot de dossiers, en une requête.
     *
     * @param list<string> $parentIds
     *
     * @return list<array{id: string, name: string, mimeType: string, size: int|null, modifiedAt: string|null, parents: list<string>, thumbnail: string|null}>
     */
    private function children(GoogleServiceAccount $account, array $parentIds): array
    {
        $clauses = array_map(static fn (string $id): string => sprintf("'%s' in parents", $id), $parentIds);

        $payload = $this->get($account, self::FILES_URI, [
            // `trashed = false` explicitement : la corbeille d'un Drive reste
            // dans le dossier et ressortirait comme un fichier vivant.
            'q' => '('.implode(' or ', $clauses).') and trashed = false',
            // `parents` est ce qui permet de savoir de quel dossier du lot
            // chaque ligne vient, donc de reconstruire son chemin.
            'fields' => 'files(id,name,mimeType,size,modifiedTime,parents,thumbnailLink)',
            'pageSize' => self::MAX_FILES,
            // Un dossier partagé depuis un Drive partagé n'est pas visible
            // sans cela, et le symptôme est une liste vide sans erreur.
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);

        if (null === $payload) {
            return [];
        }

        $rows = [];

        foreach ((array) ($payload['files'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $name = $row['name'] ?? null;
            if (!is_string($id)) {
                continue;
            }

            if (!is_string($name)) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'mimeType' => is_string($row['mimeType'] ?? null) ? $row['mimeType'] : 'application/octet-stream',
                // Google rend la taille en chaîne, et l'omet pour ses propres
                // formats - un Google Doc n'a pas d'octets tant qu'on ne l'a
                // pas exporté.
                'size' => isset($row['size']) ? (int) $row['size'] : null,
                'modifiedAt' => is_string($row['modifiedTime'] ?? null) ? $row['modifiedTime'] : null,
                'parents' => array_values(array_filter((array) ($row['parents'] ?? []), is_string(...))),
                // **Servie par le CDN de Google, sans authentification** -
                // mesuré : deux cent vingt pixels, moins d'un kilo-octet, et
                // un `200` sans le moindre en-tête. Elle voyage donc jusqu'au
                // navigateur au lieu d'être relayée, ce qui épargne un appel
                // par vignette affichée. La contrepartie est dite à l'endroit
                // où l'image est posée : le navigateur du client parle à
                // Google pour elle, ce qui ne conditionne aucun accès mais se
                // sait. Absente pour ce dont Google ne sait pas faire d'image.
                'thumbnail' => is_string($row['thumbnailLink'] ?? null) ? $row['thumbnailLink'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Le chemin du dossier d'où vient cette ligne, parmi ceux du lot.
     *
     * Un fichier peut avoir plusieurs parents dans un Drive ; on garde le
     * premier qui appartient au lot interrogé, parce que c'est celui qui l'a
     * fait remonter.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $chunk
     */
    private function parentPathOf(array $row, array $chunk): string
    {
        foreach ((array) ($row['parents'] ?? []) as $parent) {
            if (is_string($parent) && isset($chunk[$parent])) {
                return $chunk[$parent];
            }
        }

        return '';
    }

    /**
     * Un dossier partagé se lit par ce qui vient d'y arriver, pas par ordre
     * alphabétique.
     *
     * @param list<array<string, mixed>> $files
     *
     * @return list<array<string, mixed>>
     */
    private function newestFirst(array $files): array
    {
        usort($files, static fn (array $a, array $b): int => ($b['modifiedAt'] ?? '') <=> ($a['modifiedAt'] ?? ''));

        return $files;
    }

    /**
     * Le fichier lui-même, en flux.
     *
     * Rendu tel quel pour que l'appelant le relaie sans le charger en mémoire :
     * un dossier partagé contient des vidéos et des PDF de plusieurs dizaines
     * de mégaoctets, et les mettre dans une chaîne PHP pour les recracher
     * ensuite ferait tomber le serveur sur le premier gros fichier.
     *
     * Null quand Google refuse - le fichier a été retiré du partage, ou
     * supprimé. L'appelant en fait un message, pas une erreur.
     */
    /**
     * Le nom du fichier, et rien d'autre.
     *
     * **Google ne le donne pas avec le contenu.** Sa réponse à `alt=media`
     * porte bien un `Content-Disposition: attachment`, mais sans `filename` :
     * relayée telle quelle, elle ferait atterrir « 1BxY_…Kp3 » dans le dossier
     * de téléchargement du client. Le nom se demande donc à part.
     *
     * **Et il se demande à Google, jamais au navigateur.** Le laisser voyager
     * dans l'adresse reviendrait à écrire un en-tête à partir de ce qu'un
     * visiteur envoie ; ici le seul nom possible est celui que porte vraiment
     * le fichier partagé.
     *
     * @return array{name: string, mimeType: string}|null
     */
    public function metadata(GoogleServiceAccount $account, string $fileId): ?array
    {
        $payload = $this->get($account, self::FILES_URI.'/'.$fileId, [
            'fields' => 'name,mimeType',
            'supportsAllDrives' => 'true',
        ]);

        if (null === $payload || !is_string($payload['name'] ?? null)) {
            return null;
        }

        return [
            'name' => $payload['name'],
            'mimeType' => is_string($payload['mimeType'] ?? null) ? $payload['mimeType'] : '',
        ];
    }

    public function download(GoogleServiceAccount $account, string $fileId): ?ResponseInterface
    {
        $token = $this->token($account);

        if (null === $token) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::FILES_URI.'/'.$fileId, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => ['alt' => 'media', 'supportsAllDrives' => 'true'],
                'timeout' => self::TIMEOUT_SECONDS,
                'buffer' => false,
            ]);

            // Lu maintenant : sans cela l'appelant découvrirait le refus en
            // plein milieu du flux, une fois les en-têtes déjà envoyés.
            if (200 !== $response->getStatusCode()) {
                return null;
            }

            return $response;
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive download failed.', [
                'fileId' => $fileId,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Le corps d'un téléchargement, morceau par morceau.
     *
     * Posé ici plutôt que chez l'appelant pour qu'il n'ait pas à connaître le
     * client HTTP : il reçoit un itérable de chaînes et les renvoie.
     *
     * @return iterable<string>
     */
    public function stream(ResponseInterface $response): iterable
    {
        foreach ($this->httpClient->stream($response) as $chunk) {
            yield $chunk->getContent();
        }
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>|null
     */
    private function get(GoogleServiceAccount $account, string $uri, array $query): ?array
    {
        $token = $this->token($account);

        if (null === $token) {
            return null;
        }

        try {
            return $this->httpClient->request('GET', $uri, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => $query,
                'timeout' => self::TIMEOUT_SECONDS,
            ])->toArray();
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive request failed.', [
                'uri' => $uri,
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * L'assertion échangée contre un jeton d'accès, gardée le temps qu'elle
     * vaut.
     *
     * La clé du cache porte l'adresse du compte : deux installations, ou un
     * compte remplacé, ne doivent pas se partager un jeton.
     */
    private function token(GoogleServiceAccount $account): ?string
    {
        try {
            $token = $this->cache->get(
                'studio.drive.token.'.md5($account->email),
                function (ItemInterface $item, bool &$save) use ($account): ?string {
                    $item->expiresAfter(self::TOKEN_TTL_SECONDS);

                    $payload = $this->httpClient->request('POST', GoogleServiceAccount::TOKEN_URI, [
                        'body' => [
                            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                            'assertion' => $account->assertion(self::SCOPE),
                        ],
                        'timeout' => self::TIMEOUT_SECONDS,
                    ])->toArray();

                    $access = $payload['access_token'] ?? null;

                    if (!is_string($access) || '' === $access) {
                        // **Pas enregistré du tout**, et non enregistré pour
                        // une seconde : le contrat du cache garde aussi les
                        // `null`, donc un refus ferait taire l'intégration
                        // pendant cinquante minutes. Une clé qu'on vient de
                        // corriger doit marcher au rechargement suivant.
                        $save = false;

                        return null;
                    }

                    return $access;
                },
            );
        } catch (Throwable $throwable) {
            $this->logger->warning('Drive token exchange failed.', ['exception' => $throwable->getMessage()]);

            return null;
        }

        return $token;
    }
}
