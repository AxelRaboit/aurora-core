# FileTransfer - Recipient

> Destinataire d'un Transfer en mode email. Chaque recipient reçoit son
> propre lien personnel `/t/{recipient.token}` et son téléchargement est
> tracké individuellement.

## Contexte

Un `FileTransferRecipient` représente une adresse email à qui le sender
veut faire parvenir le transfer. Chaque recipient a :
- son propre `token` (différent du `Transfer.token`) - permet de
  distinguer "qui a téléchargé"
- un `downloadedAt` (datetime nullable) - timestamp du premier download
- un `lastReminderSentAt` (datetime nullable) - anti-spam pour les
  reminders

Source Nimbus :
- `app/Entity/Recipient.php`
- `app/Manager/RecipientManager.php` (si existe - sinon logique dans TransferManager)
- `app/Repository/RecipientRepository.php`

## Entité `FileTransferRecipient`

| Colonne | Type | Notes |
|---|---|---|
| `id` | bigint, seq `seq_core_file_transfer_recipient_id` | PK Aurora |
| `transferId` | FK `core_file_transfer_transfer.id` CASCADE delete | parent |
| `token` | varchar(64) unique | lien personnel `/t/{token}` ; **préservé depuis Nimbus** pour ne pas invalider les emails déjà envoyés |
| `email` | varchar(255) | validation `assert.email` ; case-insensitive uniqueness intra-transfer |
| `downloadedAt` | datetime_immutable nullable | premier téléchargement ; null = pas encore |
| `lastReminderSentAt` | datetime_immutable nullable | dernier reminder envoyé (auto ou manuel) |
| `passwordHash` | text nullable | si on veut un mot de passe per-recipient (V2 ?) - V1 = null |
| `createdAt` | timestamp | - |

Index requis :
- `(token)` unique
- `(transfer_id)` - listing
- `(transfer_id, email)` unique (case-insensitive via `LOWER(email)` index si Postgres)

## Convention 5-couches

- `FileTransferRecipientInterface` + Abstract + concrete
- `FileTransferRecipientInput` (utilisé en interne par `FileTransferManager::applyInput` lors de la création du Transfer) :

```php
class FileTransferRecipientInput {
    public function __construct(
        public readonly string $email,
    ) {}
}
```

- Manager : `FileTransferRecipientManager` exposant :

```php
class FileTransferRecipientManager implements FileTransferRecipientManagerInterface
{
    public function markDownloaded(FileTransferRecipientInterface $r): void
    {
        if ($r->getDownloadedAt() !== null) return;  // idempotent
        $r->setDownloadedAt(new DateTimeImmutable());
        $this->em->flush();
        $this->notifier->notifyDownloaded($r->getTransfer(), $r);
    }

    public function sendReminder(FileTransferRecipientInterface $r): void
    {
        $r->setLastReminderSentAt(new DateTimeImmutable());
        $this->em->flush();
        $this->notifier->notifyReminder($r->getTransfer(), $r);
    }

    protected function createRecipient(): FileTransferRecipientInterface { return new FileTransferRecipient(); }
    protected function applyInput(FileTransferRecipientInterface $r, FileTransferRecipientInputInterface $i): void
    {
        $r->setEmail(strtolower(trim($i->email)));
        $r->setToken(bin2hex(random_bytes(32)));
    }
}
```

- Serializer :

```php
class FileTransferRecipientSerializer implements FileTransferRecipientSerializerInterface
{
    public function serialize(FileTransferRecipientInterface $r): array {
        return [
            'id' => $r->getId(),
            'email' => $r->getEmail(),
            'downloadedAt' => $r->getDownloadedAt()?->format('c'),
            'lastReminderSentAt' => $r->getLastReminderSentAt()?->format('c'),
            'status' => $r->getDownloadedAt() ? 'downloaded' : 'pending',
        ];
    }
}
```

## Repository

```php
class FileTransferRecipientRepository extends ResolveTargetEntityRepository
{
    public function findOneByToken(string $token): ?FileTransferRecipientInterface;
    public function findPendingForTransfer(FileTransferInterface $t): array;     // pas encore téléchargé
    public function findOverdueForReminder(int $hoursSinceCreation, int $hoursSinceLastReminder): iterable;
    //   ↑ utilisée par scheduler reminders : recipients pendants, transfer créé il y a ≥X h, dernier reminder ≥Y h
}
```

## Tracking téléchargement

Quand un recipient (identifié par son token via session) appelle
`GET /t/{transfer.token}/download` (ou `/download/{filename}`) :

1. `PublicTransferController` regarde la session : `transfer_recipient_{transfer.token}`
2. Si présent → load `FileTransferRecipient` via son token
3. `FileTransferRecipientManager::markDownloaded($r)` (idempotent)
4. Notifier → email à l'owner

Source de vérité du recipient courant : la session, settée à `GET /t/{recipient.token}` initial (cf. [public_routes.md](public_routes.md)).

## Reminders manuels

Endpoint `POST /backend/file-transfer/api/transfers/{ownerToken}/recipients/{recipient}/remind` :
- Vérifie possession ownerToken (auth implicite via route)
- Rate-limit : 1 reminder / 24h max par recipient (via `lastReminderSentAt`)
- Appelle `FileTransferRecipientManager::sendReminder($r)`

## Reminders automatiques

Job scheduler `SendRemindersHandler` (cf. [scheduler.md](scheduler.md)) :
- itère `findOverdueForReminder(24, 48)` : recipients pendants, transfer ≥24 h, dernier reminder ≥48 h
- max 1 reminder auto par recipient (config setting `fileTransfer.autoReminderEnabled`, défaut false)

## Décisions ouvertes

- **Per-recipient password** : Nimbus a la colonne `passwordHash` sur Recipient mais ne l'utilise pas en V1 (le password est sur Transfer). On garde la colonne pour ne pas réécrire la migration plus tard ; reste null en V1.
- **Anti-spam reminders** : 24 h cooldown manuel + 48 h cooldown auto. Configurable via setting Configuration.

## Tests obligatoires

- `markDownloaded` idempotent (ne re-flush pas si déjà downloaded)
- Reminder manuel respecte le cooldown 24 h
- Email case-insensitive : `Foo@bar.com` et `foo@bar.com` sur un même transfer → contrainte unique violée
- Cascade delete Transfer → tous les recipients droppés
