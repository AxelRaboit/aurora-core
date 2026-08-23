# FileTransfer - Scheduler & async jobs

> 3 jobs `@AsSchedule` Symfony Messenger + 3 commandes console
> miroirs pour debugging et cron manuel.

## Contexte

Nimbus utilise `#[AsSchedule]` + `MainSchedule.php` qui dispatche 3
messages à chaque minute :
- `CleanupExpiredTransfersMessage`
- `SendRemindersMessage`
- `ExpireTrialsMessage` (non porté - pas de notion de trial dans Aurora)

Les handlers sont **idempotents** (skip si rien à faire) - la fréquence
1/min est sûre.

Source Nimbus :
- `src/Scheduler/MainSchedule.php`
- `src/Message/*.php`
- `src/MessageHandler/*.php`

## Schedule Aurora

⚠️ **Pas de schedule séparé par module dans Aurora.** Il y a UN seul
schedule global : `Aurora\Core\Scheduler\MainSchedule` (`#[AsSchedule('main')]`,
`ScheduleProviderInterface`). Les nouveaux messages y sont ajoutés
directement.

Modifier `src/Core/Scheduler/MainSchedule.php::getSchedule()` pour
ajouter les 3 messages FileTransfer :

```php
return new Schedule()
    ->stateful($this->cache)
    ->processOnlyLastMissedRun(true)
    ->add(RecurringMessage::cron('* * * * *', new PublishScheduledPostsMessage()))
    ->add(RecurringMessage::cron('0 3 * * *', new PurgeTrashedPostsMessage()))
    ->add(RecurringMessage::cron('0 * * * *', new CleanTempFilesMessage()))
    ->add(RecurringMessage::cron('30 * * * *', new RecoverStuckOcrJobsMessage()))
    // ── FileTransfer ──────────────────────────────────────────────
    ->add(RecurringMessage::cron('* * * * *',  new CleanupExpiredTransfersMessage()))
    ->add(RecurringMessage::cron('* * * * *',  new SendRemindersMessage()))
    ->add(RecurringMessage::cron('*/5 * * * *', new CleanupTusOrphansMessage()));
```

Le pattern Aurora utilise `RecurringMessage::cron('* * * * *', ...)`
(expression cron classique) plutôt que `every('1 minute')`. À respecter
pour cohérence avec les schedules existants.

## Job 1 - CleanupExpiredTransfers

**Fréquence** : 1/min

**Logique** :
- `FileTransferRepository::findExpiringBetween(now, now+1min)` → transferts qui passent expired dans la minute
- pour chacun :
  - `FileTransferManager::expire($transfer)` :
    - `status = Expired`
    - pour chaque file : `StorageManager::getAdapterForFile($file)->delete(buildStorageKey(...))`
    - `notifier->notifyExpired($transfer)` (optionnel, setting)
    - increment `FileTransferStats` counters

**Idempotence** : si `status` déjà Expired → skip. La transition Ready→Expired est one-shot.

**Code** :

```php
#[AsMessageHandler]
final readonly class CleanupExpiredTransfersHandler
{
    public function __construct(
        private FileTransferRepository $repository,
        private FileTransferManagerInterface $manager,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(CleanupExpiredTransfersMessage $message): void
    {
        $count = 0;
        foreach ($this->repository->findReadyAndExpiredBefore(new DateTimeImmutable()) as $transfer) {
            try {
                $this->manager->expire($transfer);
                $count++;
            } catch (Throwable $e) {
                $this->logger->error('FileTransfer expire failed', ['id' => $transfer->getId(), 'error' => $e->getMessage()]);
            }
        }
        if ($count > 0) $this->logger->info("Expired {$count} transfers");
    }
}
```

**Commande miroir** : `file-transfer:expire` (utile pour cron manuel ou cluster sans worker Messenger).

## Job 2 - SendReminders

**Fréquence** : 1/min

**Logique** :
- récupère les recipients pendants éligibles à un reminder auto via
  `FileTransferRecipientRepository::findOverdueForReminder($transferAgeH, $sinceLastReminderH)`
- défauts : transfer créé ≥ `fileTransfer.autoReminderTransferAgeH` (24 h) et dernier reminder ≥ `fileTransfer.autoReminderCooldownH` (48 h)
- **conditionnel sur setting** `fileTransfer.autoReminderEnabled` (défaut `false`)
- pour chacun :
  - `FileTransferRecipientManager::sendReminder($recipient)` → notifier email

**Idempotence** : update `lastReminderSentAt` à chaque envoi → la query suivante exclut ce recipient pendant la window de cooldown.

**Commande miroir** : `file-transfer:send-reminders`.

## Job 3 - CleanupTusOrphans

**Fréquence** : 5/min (moins urgent - espace disque)

**Logique** :
- `TusUploadService::cleanupOrphanedUploads(maxAgeSeconds)`
- maxAge depuis setting `fileTransfer.tusCleanupMaxAgeHours` × 3600 (défaut 12 h)
- supprime les chunks tmp et leurs caches dont la date de création dépasse la fenêtre

**Idempotence** : `unlink` est tolérant à un fichier déjà absent (commodes via `@`).

**Commande miroir** : `file-transfer:cleanup-tus`.

## Commandes console

Toutes sous le namespace `file-transfer:` :

```bash
php bin/console file-transfer:expire              # Job 1 manuel
php bin/console file-transfer:send-reminders      # Job 2 manuel
php bin/console file-transfer:cleanup-tus         # Job 3 manuel
php bin/console file-transfer:verify-r2           # vérif accessibilité R2 (cf. storage.md)
php bin/console file-transfer:import-from-nimbus <sql-dump>  # migration depuis Nimbus
```

Une command class par job, simple wrapper autour du handler :

```php
#[AsCommand(name: 'file-transfer:expire')]
final class ExpireCommand extends Command
{
    public function __construct(private readonly CleanupExpiredTransfersHandler $handler) {}
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ($this->handler)(new CleanupExpiredTransfersMessage());
        return Command::SUCCESS;
    }
}
```

## Configuration des routes Messenger

```yaml
# config/packages/messenger.yaml - si déjà existant, ajouter
framework:
  messenger:
    transports:
      async: '%env(MESSENGER_TRANSPORT_DSN)%'
    routing:
      Aurora\Module\FileTransfer\Scheduler\Message\CleanupExpiredTransfersMessage: async
      Aurora\Module\FileTransfer\Scheduler\Message\SendRemindersMessage: async
      Aurora\Module\FileTransfer\Scheduler\Message\CleanupTusOrphansMessage: async
```

## Décisions ouvertes

- **Worker Messenger requis** : pour que les schedules tournent, il faut `php bin/console messenger:consume async --time-limit=300` (en boucle systemd). Documenter dans le README de prod (cf. `docs/aurora-client/deployment/*.md`).
- **Fallback cron** : si pas de worker dispo, les commandes miroirs `file-transfer:*` peuvent tourner en cron classique (`* * * * * php bin/console file-transfer:expire` etc.). Documenter.
- **Lock** : éviter qu'un job soit lancé en double sur cluster multi-nodes. Symfony Messenger handler peut prendre `lock_factory` via attribut. À implémenter si besoin.

## Tests obligatoires

- Handler `CleanupExpired` : transfer expiré → status=Expired + files deleted + stats incrémentées
- Handler `SendReminders` : recipient overdue → email envoyé + lastReminderSentAt mis à jour
- Handler `CleanupTusOrphans` : tmp file de 13 h → supprimé ; 11 h → conservé
- Idempotence : 2 appels successifs sur même transfer expiré → un seul `notifyExpired` envoyé
- Commande console = même résultat que handler async
