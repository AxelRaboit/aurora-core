# FileTransfer - Transfer

> Entité racine du module. Représente une session de transfert : N
> fichiers + M destinataires + état + expiration + protection password.

## Contexte

Une `FileTransfer` est créée lorsqu'un user (auth) ou un visiteur
anonyme entame un envoi. Elle agrège :
- un ensemble de `FileTransferFile` (cascade delete + orphan removal)
- un ensemble de `FileTransferRecipient` (cascade delete + orphan removal)
- une expiration absolue (`expiresAt`)
- éventuellement un mot de passe (`passwordHash`)
- des tokens (public `token`, owner `ownerToken`, court `reference`)

Source Nimbus :
- `app/Entity/Transfer.php`
- `app/Manager/TransferManager.php`
- `app/Repository/TransferRepository.php`
- `app/Controller/Api/TransferApiController.php`

## Entité `FileTransfer`

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint, seq `seq_core_file_transfer_id` | PK Aurora |
| `token` | varchar(64) unique | lien public/recipient `/t/{token}` ; bin2hex(random_bytes(32)) ; **préservé depuis Nimbus** |
| `ownerToken` | varchar(64) unique | lien de gestion `/manage/{ownerToken}` ; **préservé depuis Nimbus** |
| `reference` | varchar(9) unique | code humain affiché en UI (ex: `7F3A2-9B1`) ; **préservé depuis Nimbus** |
| `userId` | FK `core_user.id` SET NULL | propriétaire (null si transfert anonyme) |
| `status` | enum `FileTransferStatusEnum` | pending / ready / expired / deleted |
| `expiresAt` | datetime_immutable | calculée à la création (ready) |
| `tusUploadKey` | varchar(64) nullable | référence du chunk TUS en cours pendant l'upload |
| `passwordHash` | text nullable | bcrypt-style ; null = pas de mot de passe |
| `isPublic` | bool | true = lien public, pas de recipients |
| `publicDownloadCount` | int | compteur pour le mode public |
| `senderName` | varchar(255) nullable | affiché aux recipients (fallback : `user.name`) |
| `senderMessage` | text nullable | message libre du sender |
| `createdAt` / `updatedAt` | timestamps | - |
| `deletedAt` | datetime_immutable nullable | soft delete (laisse les stats remonter) |

Index requis :
- `(token)` unique
- `(ownerToken)` unique
- `(reference)` unique
- `(user_id, status, created_at DESC)` - listing "Mes transferts" Pro
- `(status, expires_at)` - scheduler cleanup

Enum :
```php
enum FileTransferStatusEnum: string {
    case Pending  = 'pending';   // upload en cours (TUS)
    case Ready    = 'ready';     // finalisé, téléchargeable
    case Expired  = 'expired';   // passé expiresAt - fichiers supprimés
    case Deleted  = 'deleted';   // soft-delete manuel
}
```

## Convention 5-couches

### Entity (couche 1)
- `FileTransferInterface` (getters/setters typés)
- `AbstractFileTransfer` (MappedSuperclass - toute la logique Doctrine)
- `FileTransfer` (concrete non-`final`, `#[Entity]` + `#[Table('core_file_transfer_transfer')]`)

Référencer dans `AuroraBundle::$resolve_target_entities`.

### DTO (couche 2)
4 fichiers : `FileTransferInputInterface`, `FileTransferInput`,
`FileTransferInputFactoryInterface`, `FileTransferInputFactory` (avec
`#[AsAlias]`).

```php
class FileTransferInput implements FileTransferInputInterface {
    public function __construct(
        public readonly ?User $user,
        public readonly array $tusUploadKeys,           // string[]
        public readonly array $recipients,              // RecipientInput[]
        public readonly bool $isPublic,
        public readonly ?string $password,              // plain, hashé par Manager
        public readonly int $expirationHours,
        public readonly ?string $senderName,
        public readonly ?string $senderMessage,
    ) {}
}
```

### Manager (couche 3)

```php
class FileTransferManager implements FileTransferManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly UserPasswordHasherInterface $hasher,
        protected readonly TusUploadService $tus,
        protected readonly StorageManager $storage,
        protected readonly TransferFileValidator $validator,
        protected readonly FileTransferNotifierInterface $notifier,
    ) {}

    public function create(FileTransferInputInterface $input): FileTransferInterface
    {
        $transfer = $this->createTransfer();
        $this->applyInput($transfer, $input);
        $this->em->persist($transfer);

        // Finalize TUS uploads → permanent storage
        foreach ($input->tusUploadKeys as $uploadKey) {
            $upload = $this->tus->getUpload($uploadKey);
            $file = $this->createTransferFile();
            $filename = bin2hex(random_bytes(8)) . '_' . basename($upload['file_path']);
            $storageKey = $this->storage->buildStorageKey($transfer, $filename);
            $this->storage->getActiveAdapter()->store($upload['file_path'], $storageKey);
            $file->setTransfer($transfer);
            $file->setOriginalName($upload['original_name']);
            $file->setFilename($filename);
            $file->setMimeType($upload['mime_type']);
            $file->setFileSize($upload['size']);
            $file->setStorageBackend($this->storage->getActiveBackend());
            $transfer->addFile($file);
            $this->tus->deleteUpload($uploadKey);
        }

        // Add recipients
        foreach ($input->recipients as $recipientInput) {
            $r = $this->createRecipient();
            $r->setTransfer($transfer);
            $r->setEmail($recipientInput->email);
            $r->setToken(bin2hex(random_bytes(32)));
            $transfer->addRecipient($r);
        }

        $transfer->setStatus(FileTransferStatusEnum::Ready);
        $this->em->flush();
        $this->auditCreated($transfer);
        $this->notifier->notifyReady($transfer, $input->password);

        return $transfer;
    }

    public function delete(FileTransferInterface $transfer): void
    {
        // Delete files from storage
        foreach ($transfer->getFiles() as $file) {
            $adapter = $this->storage->getAdapterForFile($file);
            $adapter->delete($this->storage->buildStorageKey($transfer, $file->getFilename()));
        }
        $transfer->setStatus(FileTransferStatusEnum::Deleted);
        $transfer->setDeletedAt(new DateTimeImmutable());
        $this->em->flush();
        $this->auditDeleted($transfer);
    }

    protected function createTransfer(): FileTransferInterface { return new FileTransfer(); }
    protected function createTransferFile(): FileTransferFileInterface { return new FileTransferFile(); }
    protected function createRecipient(): FileTransferRecipientInterface { return new FileTransferRecipient(); }

    protected function applyInput(FileTransferInterface $t, FileTransferInputInterface $i): void
    {
        $t->setUser($i->user);
        $t->setToken(bin2hex(random_bytes(32)));
        $t->setOwnerToken(bin2hex(random_bytes(32)));
        $t->setReference($this->generateReference());
        $t->setIsPublic($i->isPublic);
        $t->setExpiresAt((new DateTimeImmutable())->modify("+{$i->expirationHours} hours"));
        $t->setSenderName($i->senderName);
        $t->setSenderMessage($i->senderMessage);
        if ($i->password) {
            $t->setPasswordHash($this->hasher->hashPassword(/* fake user */, $i->password));
        }
        $t->setStatus(FileTransferStatusEnum::Pending);
    }

    protected function generateReference(): string
    {
        // 9 chars : 5 hex + '-' + 3 hex
        return strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
    }

    protected function auditPayload(FileTransferInterface $t): array { /* … */ }
    protected function auditCreated(FileTransferInterface $t): void { /* … */ }
    protected function auditUpdated(FileTransferInterface $t): void { /* … */ }
    protected function auditDeleted(FileTransferInterface $t): void { /* … */ }
}
```

### Serializer (couche 4)

```php
class FileTransferSerializer implements FileTransferSerializerInterface
{
    public function serialize(FileTransferInterface $t): array {
        return [
            'id' => $t->getId(),
            'token' => $t->getToken(),
            'ownerToken' => $t->getOwnerToken(),
            'reference' => $t->getReference(),
            'status' => $t->getStatus()->value,
            'expiresAt' => $t->getExpiresAt()->format('c'),
            'isPublic' => $t->isPublic(),
            'hasPassword' => $t->getPasswordHash() !== null,
            'senderName' => $t->getSenderName(),
            'senderMessage' => $t->getSenderMessage(),
            'publicDownloadCount' => $t->getPublicDownloadCount(),
            'files' => array_map($this->fileSerializer->serialize(...), $t->getFiles()->toArray()),
            'recipients' => array_map($this->recipientSerializer->serialize(...), $t->getRecipients()->toArray()),
        ];
    }
}
```

### Repository

```php
class FileTransferRepository extends ResolveTargetEntityRepository
{
    public function findByToken(string $token): ?FileTransferInterface;
    public function findByOwnerToken(string $ownerToken): ?FileTransferInterface;
    public function findByReference(string $reference): ?FileTransferInterface;
    public function findUserTransfersPaginated(User $user, int $page, int $perPage): Paginator;
    public function findExpiringBetween(DateTimeImmutable $from, DateTimeImmutable $to): iterable;  // scheduler
    public function findReadyTransfersWithPendingRecipients(): iterable;                            // reminders
}
```

## Controller backend

`Aurora\Module\FileTransfer\Transfer\Controller\Backend\FileTransferController` -
endpoints `JsonResponse` :

| Méthode | Route | Auth |
|---|---|---|
| `list()` | `GET /backend/file-transfer/api/transfers` | ROLE_USER (owner own) |
| `get()` | `GET /backend/file-transfer/api/transfers/{token}` | ROLE_USER |
| `create()` | `POST /backend/file-transfer/api/transfers` | ROLE_USER ou anon (limites stricter) |
| `finalize()` | `POST /backend/file-transfer/api/transfers/{token}/finalize` | (utilise tusUploadKeys) |
| `delete()` | `DELETE /backend/file-transfer/api/transfers/{ownerToken}` | possession ownerToken |
| `remind()` | `POST /backend/file-transfer/api/transfers/{ownerToken}/recipients/{recipient}/remind` | possession ownerToken |
| `abandon()` | `DELETE /backend/file-transfer/api/transfers/{token}/abandon` | pendant upload TUS |

## Décisions ouvertes

- **Anonymous upload** : porter le flow Nimbus où un visiteur non-authentifié peut créer un transfer ? (avec rate-limit + limites stricter) ou réserver à `ROLE_USER` ? - défaut : porter le flow anonyme, rate-limit via Symfony RateLimiter.
- **Soft-delete vs hard-delete** : Nimbus marque `status=deleted` mais garde la ligne pour les stats (`TransferStats`). On garde ce pattern.
- **Audit log** : Aurora a `AuditLog` ? Si oui, brancher les hooks `auditCreated/Updated/Deleted`. Si non, no-op hooks.

## Tests obligatoires

- Création → status pending → finalize → ready
- Expiration via scheduler
- Delete cascade (files + recipients + storage)
- Password hash + verify
- Token uniqueness (collision retry)
- Migration : token Nimbus importé → `findByToken` OK
