# FileTransfer - TransferFile

> Un fichier individuel d'un Transfer. **La colonne qui pointe vers
> l'objet R2 est ici** - voir [storage.md](storage.md) pour la formule de
> reconstruction de la clé.

## Contexte

`FileTransferFile` est l'enregistrement DB qui mappe un objet stocké
(local ou R2) à un Transfer. Chaque ligne porte :
- son nom d'origine (`originalName`) - affiché à l'utilisateur
- son nom de stockage randomisé (`filename`) - utilisé pour construire la clé
- son backend de stockage (`storageBackend` enum) - permet le mixte local/R2

Source Nimbus :
- `app/Entity/TransferFile.php`
- `app/Repository/TransferFileRepository.php`

## Entité `FileTransferFile`

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint, seq `seq_core_file_transfer_file_id` | PK Aurora |
| `transferId` | FK `core_file_transfer_transfer.id` CASCADE delete | parent |
| `originalName` | varchar(255) | nom affiché ; sanitize XSS à l'affichage |
| `filename` | varchar(255) | suffixe randomisé `<8hex>_<basename>` ; **préservé depuis Nimbus** |
| `mimeType` | varchar(100) nullable | détecté via finfo lors du finalize |
| `fileSize` | bigint | octets, signé par sécurité |
| `storageBackend` | enum `FileTransferStorageBackendEnum` | local / r2 - figé à la création de la ligne |
| `createdAt` | timestamp | - |

Pas d'`updatedAt` - un TransferFile est immutable une fois finalisé.

Index requis :
- `(transfer_id)` - listing des files d'un transfer
- pas besoin d'unique sur `filename` global (déjà unique au sein d'un transfer via randomisation 8 bytes)

Enum :
```php
enum FileTransferStorageBackendEnum: string {
    case Local = 'local';
    case R2    = 'r2';
}
```

## Convention 5-couches

- `FileTransferFileInterface` + `AbstractFileTransferFile` + `FileTransferFile`
- DTO : `FileTransferFileInput` (utilisé en interne par `FileTransferManager::create()` quand il finalise depuis TUS - pas exposé en route)
- Manager : `FileTransferFileManager` - moins étoffé, sert surtout au delete individuel et au calcul du storage key
- Serializer : `FileTransferFileSerializer`

```php
class FileTransferFileSerializer implements FileTransferFileSerializerInterface
{
    public function serialize(FileTransferFileInterface $f): array {
        return [
            'id' => $f->getId(),
            'originalName' => $f->getOriginalName(),
            'mimeType' => $f->getMimeType(),
            'fileSize' => $f->getFileSize(),
            'sizeHuman' => $this->formatBytes($f->getFileSize()),
            'downloadUrl' => "/t/{$f->getTransfer()->getToken()}/download/{$f->getFilename()}",
            'previewUrl' => $this->canPreview($f) ? "/t/{$f->getTransfer()->getToken()}/preview/{$f->getFilename()}" : null,
        ];
    }

    private function canPreview(FileTransferFileInterface $f): bool {
        return in_array($f->getMimeType(), ['image/jpeg', 'image/png', 'application/pdf', /*…*/], true);
    }
}
```

## Repository

```php
class FileTransferFileRepository extends ResolveTargetEntityRepository
{
    public function findOneByTransferAndFilename(FileTransferInterface $transfer, string $filename): ?FileTransferFileInterface;
    public function totalSizeForUser(User $user): int;  // pour Mes transferts overview
}
```

## Storage key - reconstruction

**Toujours dérivée**, jamais stockée explicitement :

```php
// Dans FileTransferFileManager ou StorageManager
public function getStorageKey(FileTransferFileInterface $file): string {
    return $file->getTransfer()->getToken() . '/' . $file->getFilename();
}
```

C'est cette même clé qui sert à :
- `LocalStorageAdapter` : chemin disque sous `var/uploads/file-transfer/transfers/`
- `R2StorageAdapter` : object key R2

Cf. [storage.md](storage.md) pour la migration depuis Nimbus.

## Validation à la finalize

À la création (par `FileTransferManager::create()` depuis les uploadKeys
TUS), chaque file passe par `TransferFileValidator` :
- extension whitelist (cf. [validators.md](validators.md))
- MIME-type whitelist
- ZIP : vérification du contenu (pas d'exécutable inside)
- total size ≤ `fileTransfer.maxSizeMb` (setting Configuration)
- count ≤ `fileTransfer.maxFiles`

Si une validation échoue, **rollback complet** : aucun file persisté, le
Transfer reste en `status=pending` (et le TUS upload est conservé pour
permettre une nouvelle tentative).

## Suppression individuelle

Pas exposée en V1. Un user supprime un Transfer entier (donc tous ses
files cascade). Si le besoin émerge plus tard : endpoint
`DELETE /backend/file-transfer/api/transfers/{ownerToken}/files/{file}`
+ hook `FileTransferFileManager::delete($file)` qui appelle le bon
adapter et flush.

## Décisions ouvertes

- **MIME-type detection** : utiliser `finfo_open(FILEINFO_MIME_TYPE)` au moment du finalize (pas confiance dans le header HTTP du client TUS). Cf. validators.md.
- **Preview URLs** : pour les images, on peut sécuriser via la session (recipient connecté à `/t/{token}`) puis route catch-all `/t/{token}/preview/{filename}` qui retourne le binary inline. Pas de signed URL pour V1.

## Tests obligatoires

- Création : `storageBackend` figé à la valeur de `StorageManager::getActiveBackend()` au moment de l'insert
- Reconstruction clé : `getStorageKey()` = `{transfer.token}/{filename}` à 100%
- Cascade delete : drop Transfer → drop tous les rows TransferFile
- Migration : import nimbus → `filename` préservé → R2 accessible via la clé reconstruite
