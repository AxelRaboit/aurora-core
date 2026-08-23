# FileTransfer - Storage abstraction

> **Pièce critique du port**. Contient la stratégie pour réutiliser les
> mêmes ids R2 que Nimbus afin que les objets existants restent
> accessibles sans déplacement physique.

## Contexte

Nimbus a une abstraction `StorageAdapterInterface` avec deux
implémentations : `LocalStorageAdapter` (filesystem) et `R2StorageAdapter`
(Cloudflare R2 via AWS SDK). La sélection du backend actif est lue
depuis `ApplicationParameter.storage_backend`.

Chaque `TransferFile` enregistre son backend dans `storageBackend` -
permet de migrer progressivement et de servir des fichiers depuis
plusieurs backends en parallèle.

## Formule de la clé - invariante

```
storageKey = "{transfer.token}/{transferFile.filename}"
```

Où :
- `transfer.token` = 64 hex chars, randomisé à la création du Transfer (`bin2hex(random_bytes(32))`)
- `transferFile.filename` = `<8 hex>_<basename original>` (préfixe random pour éviter les collisions intra-transfer)

**Cette formule est exactement la même que Nimbus.** C'est ce qui rend
possible la réutilisation des ids R2.

Implémentation Aurora dans `StorageManager` :

```php
final readonly class StorageManager
{
    public function __construct(
        private LocalStorageAdapter $local,
        private R2StorageAdapter $r2,
        private SettingProviderInterface $settings,
    ) {}

    public function getActiveBackend(): FileTransferStorageBackendEnum
    {
        return $this->settings->get('fileTransfer.storageBackend', 'local') === 'r2'
            ? FileTransferStorageBackendEnum::R2
            : FileTransferStorageBackendEnum::Local;
    }

    public function getActiveAdapter(): StorageAdapterInterface
    {
        return match ($this->getActiveBackend()) {
            FileTransferStorageBackendEnum::Local => $this->local,
            FileTransferStorageBackendEnum::R2    => $this->r2,
        };
    }

    public function getAdapterForFile(FileTransferFileInterface $file): StorageAdapterInterface
    {
        return match ($file->getStorageBackend()) {
            FileTransferStorageBackendEnum::Local => $this->local,
            FileTransferStorageBackendEnum::R2    => $this->r2,
        };
    }

    public function buildStorageKey(FileTransferInterface $transfer, string $filename): string
    {
        return $transfer->getToken() . '/' . $filename;
    }
}
```

## Interface

```php
interface StorageAdapterInterface
{
    /** Move a temp file into permanent storage at $storageKey. */
    public function store(string $sourcePath, string $storageKey): void;

    /** Delete the stored object (idempotent - no error if missing). */
    public function delete(string $storageKey): void;

    public function exists(string $storageKey): bool;

    /**
     * Build an HTTP response that delivers the file to the client.
     * - Local : BinaryFileResponse (avec X-Sendfile en prod)
     * - R2    : RedirectResponse vers presigned URL (5 min TTL)
     */
    public function createDownloadResponse(
        string $storageKey,
        string $originalName,
        ?string $mimeType,
        bool $inline,
    ): Response;

    /**
     * Renvoie un chemin local. Pour Local : tel quel. Pour R2 : download
     * dans un tmp et retourne le path. Caller est responsable du
     * unlink() après usage. Utilisé pour la construction de ZIP.
     */
    public function getLocalPath(string $storageKey): string;
}
```

## LocalStorageAdapter

```php
final readonly class LocalStorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private string $baseDir,          // %kernel.project_dir%/var/uploads/file-transfer/transfers
        private BinaryFileServer $server, // Aurora\Core\Storage\BinaryFileServer (path-traversal guard + X-Sendfile)
    ) {}

    public function store(string $source, string $key): void
    {
        $target = $this->baseDir . '/' . $key;
        $dir = dirname($target);
        if (!is_dir($dir)) mkdir($dir, 0o750, true);
        if (!rename($source, $target)) throw new StorageException("Cannot move $source → $target");
    }

    public function delete(string $key): void { @unlink($this->baseDir . '/' . $key); }

    public function exists(string $key): bool { return is_file($this->baseDir . '/' . $key); }

    public function createDownloadResponse(string $key, string $originalName, ?string $mimeType, bool $inline): Response
    {
        return $this->server->serve($this->baseDir . '/' . $key, $originalName, $mimeType, $inline);
    }

    public function getLocalPath(string $key): string { return $this->baseDir . '/' . $key; }
}
```

## R2StorageAdapter

```php
final readonly class R2StorageAdapter implements StorageAdapterInterface
{
    public function __construct(
        private S3Client $s3,        // AWS SDK v3 configured for R2 endpoint
        private string $bucket,      // %env(R2_BUCKET)% - DOIT être identique à celui de Nimbus pour réutilisation
    ) {}

    public function store(string $source, string $key): void
    {
        $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => fopen($source, 'r'),
            'ContentType' => mime_content_type($source) ?: 'application/octet-stream',
        ]);
        @unlink($source);  // clean temp
    }

    public function delete(string $key): void
    {
        try { $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]); }
        catch (S3Exception $e) { /* idempotent - log et continue */ }
    }

    public function exists(string $key): bool
    {
        try { $this->s3->headObject(['Bucket' => $this->bucket, 'Key' => $key]); return true; }
        catch (S3Exception) { return false; }
    }

    public function createDownloadResponse(string $key, string $originalName, ?string $mimeType, bool $inline): Response
    {
        $cmd = $this->s3->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ResponseContentDisposition' => sprintf(
                '%s; filename="%s"',
                $inline ? 'inline' : 'attachment',
                addslashes($originalName),
            ),
            'ResponseContentType' => $mimeType ?? 'application/octet-stream',
        ]);
        $request = $this->s3->createPresignedRequest($cmd, '+5 minutes');
        return new RedirectResponse((string) $request->getUri());
    }

    public function getLocalPath(string $key): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'r2_dl_');
        $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $tmp]);
        return $tmp;  // caller doit unlink()
    }
}
```

## Configuration

Settings exposés via le module Configuration Aurora :

| Setting key | Type | Défaut | Description |
|---|---|---|---|
| `fileTransfer.storageBackend` | enum | `local` | `local` ou `r2` |
| `fileTransfer.r2Endpoint` | string | - | URL R2 (ex: `https://<account>.r2.cloudflarestorage.com`) |
| `fileTransfer.r2Bucket` | string | - | Nom du bucket (**identique à Nimbus pour la migration**) |
| `fileTransfer.r2AccessKeyId` | secret | - | Credential R2 |
| `fileTransfer.r2SecretAccessKey` | secret | - | Credential R2 |

Les secrets passent par Symfony Vault (`secrets:set`) plutôt que la table
Configuration en clair - fournir un `SettingResolver` qui lit `%env(R2_…)%`
en priorité.

## Migration depuis Nimbus

### Étape 1 - Mêmes credentials R2

Définir `R2_BUCKET`, `R2_ENDPOINT`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`
**identiques** à ceux de Nimbus dans l'env du serveur Aurora. Aucun objet
ne bouge.

### Étape 2 - Dump SQL Nimbus → import Aurora

Script PHP (idéalement une commande `file-transfer:import-from-nimbus
<sql-dump-path>`) qui :

1. Lit le dump Nimbus (`transfers`, `transfer_files`, `recipients`)
2. Pour chaque `transfers` row :
   - Crée un `core_file_transfer_transfer` row en préservant `token`,
     `ownerToken`, `reference`, `passwordHash`, `expiresAt`, `status`,
     `isPublic`, `publicDownloadCount`, `senderName`, `senderMessage`
   - Mappe `userId` Nimbus → `userId` Aurora (lookup par email, ou table
     de correspondance fournie en option)
   - `id` est régénéré via la sequence Aurora (PK différente - OK)
3. Pour chaque `transfer_files` row :
   - Crée un `core_file_transfer_file` row en préservant `originalName`,
     `filename`, `mimeType`, `fileSize`, `storageBackend`
   - `transferId` = nouveau Aurora ID du Transfer parent
4. Pour chaque `recipients` row :
   - Crée un `core_file_transfer_recipient` en préservant `token`,
     `email`, `downloadedAt`, `lastReminderSentAt`

**Critère de succès** : `findByToken($oldToken)` retourne le Transfer
importé ; `getStorageKey($file)` reconstitue exactement la clé Nimbus.

### Étape 3 - Validation R2

Commande `file-transfer:verify-r2` qui :
- itère sur tous les `FileTransferFile` avec `storageBackend = r2`
- pour chacun, calcule la clé et appelle `R2StorageAdapter::exists($key)`
- log les manquants (≥1 fichier absent → cutover bloqué)

### Étape 4 - Cutover

- DNS / reverse-proxy : redirection `nimbus.tld/t/*` → `aurora.tld/t/*` (préservation path → token identique → match côté Aurora)
- Surveillance 7 jours
- Décommissionnement Nimbus

## Décisions ouvertes

- **Réutiliser `Aurora\Core\Storage\BinaryFileServer`** côté LocalAdapter pour le path-traversal guard + X-Sendfile (déjà battle-tested dans Aurora). Pas besoin de réécrire.
- **R2 vs S3 vs MinIO** : on garde une seule classe `R2StorageAdapter` (l'API est S3-compatible). Si plus tard un client veut MinIO ou AWS S3 vrai, il étend `R2StorageAdapter` et change l'endpoint via setting - pas de surface API nouvelle.
- **Presigned vs proxy** : R2 sert via redirect presigned URL (offload du trafic depuis le serveur PHP). Risque : URL exposée fuite si elle finit en log proxy → TTL court (5 min) est la mitigation.

## Tests obligatoires

- `buildStorageKey()` = `{token}/{filename}` à 100% (test paramétré sur 100 valeurs)
- `LocalStorageAdapter::store` crée bien le sous-dossier `{baseDir}/{token}/`
- `R2StorageAdapter::store` envoie l'objet avec la bonne clé (mock SDK)
- `getAdapterForFile()` retourne le bon adapter selon `storageBackend` enum
- Migration : import Nimbus dump → exists R2 = true pour 100% des files
