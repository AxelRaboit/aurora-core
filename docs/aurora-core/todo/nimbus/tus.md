# FileTransfer - TUS upload service

> Protocole TUS résumable (https://tus.io) pour upload de gros fichiers
> fragmentés. Permet de pause/reprendre, sans avoir besoin de réuploader
> depuis 0.

## Contexte

TUS expose 5 verbes HTTP (`OPTIONS`, `POST`, `HEAD`, `PATCH`, `DELETE`)
sous le préfixe `/tus`. Le client (Vue + `tus-js-client`) découpe les
fichiers en chunks (5-10 Mo), envoie chaque chunk en `PATCH`, et finalise
ensuite via l'API métier (`POST /api/transfers/{token}/finalize` qui
prend les uploadKeys TUS comme input).

Source Nimbus :
- `app/Service/TusUploadService.php`
- `app/Service/TusUploadServiceInterface.php`
- `app/Controller/TusController.php`

Bibliothèque : `ankitpokhrel/tus-php` (composer)

## Flow complet

```
1. CLIENT  POST /tus              Upload-Length: <bytes>
                                  Upload-Metadata: filename=<base64>,filetype=<base64>,transferToken=<base64>
   SERVER  → 201 Created
           Location: /tus/<uploadKey>

2. CLIENT  HEAD /tus/<uploadKey>
   SERVER  → 200 OK
           Upload-Offset: 0

3. CLIENT  PATCH /tus/<uploadKey> Upload-Offset: 0
                                  Content-Type: application/offset+octet-stream
                                  Content-Length: 5000000
                                  <5MB binary>
   SERVER  → 204 No Content
           Upload-Offset: 5000000

   (répété jusqu'à Upload-Offset == Upload-Length)

4. CLIENT  POST /backend/file-transfer/api/transfers
           Body: { tusUploadKeys: ["<k1>", "<k2>", …], recipients: [...], expirationHours: 24, password: null, isPublic: false }
   SERVER  FileTransferManager::create() :
            - load TUS metadata pour chaque uploadKey
            - move file from var/uploads/file-transfer/tus_tmp/<key> → backend (local/r2)
            - persist Transfer + Files + Recipients
            - notify recipients
            - return Transfer json + ownerToken (caller redirige vers /manage/{ownerToken})
```

## TusUploadService

```php
class TusUploadService implements TusUploadServiceInterface
{
    public function __construct(
        private readonly string $tusUploadPath,   // var/uploads/file-transfer/tus_tmp
        private readonly string $tusCachePath,    // var/uploads/file-transfer/tus_cache
    ) {}

    public function buildServer(Request $request): TusServer
    {
        $server = new TusServer(new TusFileStore($this->tusCachePath));
        $server->setApiPath('/tus')->setUploadDir($this->tusUploadPath);
        return $server;
    }

    public function getUpload(string $uploadKey): ?array
    {
        $cache = new TusFileStore($this->tusCachePath);
        $data = $cache->get($uploadKey);
        if (!$data) return null;
        return [
            'file_path' => $data['file_path'],         // var/uploads/file-transfer/tus_tmp/<key>
            'original_name' => $data['metadata']['filename'] ?? basename($data['file_path']),
            'mime_type' => $data['metadata']['filetype'] ?? null,
            'size' => $data['size'] ?? filesize($data['file_path']),
            'transfer_token' => $data['metadata']['transferToken'] ?? null,
        ];
    }

    public function uploadExists(string $uploadKey): bool
    {
        return (new TusFileStore($this->tusCachePath))->get($uploadKey) !== null;
    }

    public function deleteUpload(string $uploadKey): void
    {
        $data = $this->getUpload($uploadKey);
        if (!$data) return;
        @unlink($data['file_path']);
        (new TusFileStore($this->tusCachePath))->delete($uploadKey);
    }

    public function deleteUploadsByTransferToken(string $transferToken): void
    {
        // itère le cache, filtre par metadata.transferToken, supprime
    }

    public function cleanupOrphanedUploads(int $maxAgeSeconds): int
    {
        // itère le cache, si created_at < now - maxAge → delete
        // retourne le count supprimé
    }
}
```

## TusController

```php
class TusController extends AbstractController
{
    public function __construct(private readonly TusUploadService $tus) {}

    #[Route('/tus/{uploadKey}', methods: ['POST', 'OPTIONS', 'HEAD', 'PATCH', 'DELETE'], requirements: ['uploadKey' => '[a-f0-9-]+'])]
    #[Route('/tus', methods: ['POST', 'OPTIONS'])]
    public function handle(Request $request): Response
    {
        $server = $this->tus->buildServer($request);
        return $server->serve();   // tus-php renvoie déjà une PSR-7 → Symfony Response
    }
}
```

Bridge PSR-7 ↔ Symfony : utiliser `symfony/psr-http-message-bridge` si
nécessaire. `tus-php` v2+ a un mode `getResponse()` directement compatible.

## Auth & rate-limit

- **`/tus` est public** (anonymous upload Nimbus) - un visiteur peut uploader sans compte.
- **Rate-limit** : Symfony RateLimiter sur la route, par IP. Setting `fileTransfer.tusRateLimitPerHour` (défaut 50).
- **Quota anonyme** : limites stricter (max size, max files) appliquées au `finalize`, pas au TUS lui-même.

## Layout filesystem

```
var/uploads/file-transfer/
├── tus_tmp/
│   ├── <uploadKey1>            # chunks concaténés (1 par upload en cours)
│   └── <uploadKey2>
└── tus_cache/
    ├── <uploadKey1>.cache      # JSON metadata (file_path, name, size, …)
    └── <uploadKey2>.cache
```

Les chunks ne quittent jamais `tus_tmp/` tant que le client n'a pas fini.
Le finalize déplace (rename) le fichier complet vers le backend actif via
`StorageAdapter::store($tmpPath, $storageKey)`.

## Reprise d'upload (resume-check)

Endpoint `GET /backend/file-transfer/api/transfers/{token}/resume-check?uploadKey=<k>` :

```php
public function resumeCheck(string $token, Request $request): JsonResponse
{
    $key = $request->query->get('uploadKey');
    $upload = $this->tus->getUpload($key);
    if (!$upload) return $this->json(['exists' => false]);
    return $this->json([
        'exists' => true,
        'offset' => filesize($upload['file_path']),
        'size' => $upload['size'],
    ]);
}
```

Le client compare `offset/size` et reprend où il en était.

## Abandon

Endpoint `DELETE /backend/file-transfer/api/transfers/{token}/abandon` :
- Pour chaque uploadKey passé en body
- Appelle `TusUploadService::deleteUpload(key)` → libère l'espace disque
- Si un Transfer pending existait → cascade delete

## Cleanup orphelins

Job scheduler `CleanupTusMessage` (cf. [scheduler.md](scheduler.md)) :
- toutes les 5 minutes : `$tus->cleanupOrphanedUploads(12 * 3600)` (12 h
  par défaut, setting `fileTransfer.tusCleanupMaxAgeHours`)
- supprime les chunks dont le cache est vieux et qui n'ont jamais été
  finalisés (user a fermé l'onglet, etc.)

## Décisions ouvertes

- **Bibliothèque** : `ankitpokhrel/tus-php` (battle-tested, maintenu). Si pas disponible en compatibilité Symfony 7, écrire un handler custom (verbose mais doable - la spec TUS est petite).
- **Stockage chunks** : filesystem (comme Nimbus). Pas de Redis pour V1 - overkill et déploiement plus lourd.
- **Direct-to-R2 upload** : V2 possible (TUS chunks écrits directement dans R2 multipart upload), mais V1 reste filesystem → finalize move vers R2. Coût : 1 lecture + 1 écriture par finalize ; acceptable pour la taille de Nimbus.

## Tests obligatoires

- POST /tus + HEAD + 3 PATCH + finalize → fichier complet dans backend, cache TUS nettoyé
- Resume après crash : HEAD retourne offset correct, PATCH reprend
- Abandon mid-upload → tmp + cache nettoyés
- Cleanup orphan > maxAge → supprimé ; < maxAge → conservé
- Rate-limit POST /tus déclenche 429 après seuil
