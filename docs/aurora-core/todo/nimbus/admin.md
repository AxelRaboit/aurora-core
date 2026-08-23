# FileTransfer - Admin backend

> Pages backend pour l'admin : liste des transferts, stats agrégées,
> settings Configuration.

## Contexte

Nimbus a un `Dev/*` controller bundle qui couvre :
- Dashboard agrégé (charts users + transfers)
- Liste users (CRUD)
- Liste transfers (filter, page)
- Liste access requests (approve/reject)
- Parameters editor

**Côté Aurora, beaucoup est déjà couvert** :
- User CRUD → `Platform/User`
- Access requests → `Platform/Auth/AccessRequest*`
- App parameters → `Configuration` module (settings editor générique)
- Admin dashboard → `Core/Backend/Dashboard*`

Reste à porter :
- **Liste des transferts admin** (tous users, pas juste le sien)
- **Stats agrégées** (cumul deletions, totals, charts)
- **Onglet settings Configuration** spécifique au module

Source Nimbus :
- `src/Controller/Dev/TransfersController.php`
- `src/Controller/Dev/DashboardController.php` (parties transfer-related)
- `src/Controller/Dev/ParametersController.php` (à ne PAS porter, remplacé par Configuration)

## Pages backend

| Route | Vue Aurora | Permission | Purpose |
|---|---|---|---|
| `/backend/file-transfer/transfers` | `AdminTransfersApp.vue` | `file_transfer.admin` | Liste de tous les transferts (filter status, sender, date) |
| `/backend/file-transfer/transfers/{ownerToken}` | `AdminTransferDetailApp.vue` | `file_transfer.admin` | Détail d'un transfer (files, recipients, actions admin) |
| `/backend/file-transfer/stats` | `StatsApp.vue` | `file_transfer.admin` | Stats globales + charts |
| `/backend/file-transfer/my-transfers` | `MyTransfersApp.vue` | `ROLE_USER` | Liste des transferts du user courant |
| `/backend/file-transfer/new` | `NewTransferApp.vue` | `ROLE_USER` ou anon (selon setting) | Formulaire de création (cf. [frontend.md](frontend.md)) |

Aucune page "Parameters" - les settings sont éditables via le module
Configuration standard d'Aurora (onglet "File Transfer").

## NavItems

Pattern Aurora confirmé (cf. `NotesModule::getNavSections()`) :
`getNavSections()` retourne `list<NavSection>`, chaque section contient
des `NavItem` (route name, label key, icon, requiredPrivilege).

```php
// src/Module/FileTransfer/FileTransferModule.php
public function getNavPermissions(): array
{
    return [
        new NavPermission('file_transfer.use'),       // user créer / voir ses transferts
        new NavPermission('file_transfer.admin'),     // admin all transferts
    ];
}

public function getNavSections(): array
{
    return [
        new NavSection('file_transfer', [
            new NavItem(
                'file_transfer_backend_new',
                'backend.nav.file_transfer.new',
                'upload-cloud',
                requiredPrivilege: 'file_transfer.use',
                descriptionKey: 'backend.nav.file_transfer.new_description',
            ),
            new NavItem(
                'file_transfer_backend_my_transfers',
                'backend.nav.file_transfer.my_transfers',
                'send',
                requiredPrivilege: 'file_transfer.use',
            ),
            new NavItem(
                'file_transfer_backend_admin_transfers',
                'backend.nav.file_transfer.admin_transfers',
                'list',
                requiredPrivilege: 'file_transfer.admin',
            ),
            new NavItem(
                'file_transfer_backend_stats',
                'backend.nav.file_transfer.stats',
                'bar-chart',
                requiredPrivilege: 'file_transfer.admin',
            ),
        ], priority: 30),
    ];
}

public function getCatalogNavSections(): array
{
    return $this->getNavSections();
}
```

## Permissions

Pattern Aurora confirmé : déclarer les permissions via `NavPermission`
dans `FileTransferModule::getNavPermissions()` (cf. bloc NavItems
ci-dessus). Le `ModulePermissionVoter` (`Core/Module/Security/`) résout
automatiquement les contrôles `#[IsGranted('file_transfer.use')]` /
`#[IsGranted('file_transfer.admin')]` sur les controllers.

Permissions exposées :
- `file_transfer.use` - créer un transfer + voir les siens (mappé à `ROLE_USER` par défaut, configurable côté admin)
- `file_transfer.admin` - gérer tous les transferts + voir stats (mappé à `ROLE_ADMIN`)

L'attribution des privilèges à des rôles est gérée par le système de
permissions Aurora (`PermissionRegistry`) - pas de code spécifique à
écrire dans le module au-delà des `NavPermission` ci-dessus.

## Settings Configuration

Pattern Aurora confirmé (référence : `NotesMarkdownConfigurationTabProvider`,
`EditorialConfigurationTabProvider`, `PhotoConfigurationTabProvider`,
`GedConfigurationTabProvider`) :

1. Créer un enum `FileTransferSettingEnum` avec une case par setting,
   chaque case exposant `getKey()`, `getType()`, `getLabel()`,
   `getDescription()`, `getDefaultValue()` (cf. `MarkdownNoteSettingEnum`).
2. Créer `FileTransferConfigurationTabProvider implements ConfigurationTabProviderInterface`
   tagué `aurora.configuration_tab_provider`.
3. `getTabs()` retourne `[new ConfigurationTab(id, priority, fields)]`
   où `fields` est un `list<SettingFieldDescriptor>`.

```php
// src/Module/FileTransfer/Setting/FileTransferConfigurationTabProvider.php
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab;
use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;
use Aurora\Module\Configuration\Setting\Configuration\SettingFieldDescriptor;

final readonly class FileTransferConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function getTabs(): array
    {
        $fields = [];
        foreach (FileTransferSettingEnum::cases() as $case) {
            $fields[] = new SettingFieldDescriptor(
                key: $case->getKey(),
                type: $case->getType(),
                labelKey: $case->getLabel(),
                descriptionKey: $case->getDescription(),
                defaultValue: $case->getDefaultValue(),
            );
        }

        return [
            new ConfigurationTab(id: 'file_transfer', priority: 110, fields: $fields),
        ];
    }
}
```

Settings exposés (clés de `FileTransferSettingEnum`) - groupés par thème
pour lisibilité, mais l'enum reste plat :

**Quotas** : `fileTransfer.maxSizeMb` (int, 100), `fileTransfer.maxFiles`
(int, 20), `fileTransfer.maxRecipients` (int, 20),
`fileTransfer.maxExpiryHours` (int, 168),
`fileTransfer.anonymousUploadEnabled` (bool, true),
`fileTransfer.anonymousMaxSizeMb` (int, 100),
`fileTransfer.anonymousRateLimitPerHour` (int, 5).

**Stockage** : `fileTransfer.storageBackend` (enum local|r2),
`fileTransfer.r2Endpoint` (string), `fileTransfer.r2Bucket` (string),
`fileTransfer.r2AccessKeyId` (secret/env-only), `fileTransfer.r2SecretAccessKey` (secret/env-only).

**Sécurité** : `fileTransfer.allowedExtensions` (csv),
`fileTransfer.allowedMimeTypes` (csv),
`fileTransfer.forbiddenZipExtensions` (csv),
`fileTransfer.zipBombRatioMax` (int, 100),
`fileTransfer.unlockMaxAttempts` (int, 5),
`fileTransfer.unlockWindowMinutes` (int, 10).

**Schedule** : `fileTransfer.tusCleanupMaxAgeHours` (int, 12),
`fileTransfer.autoReminderEnabled` (bool, false),
`fileTransfer.autoReminderTransferAgeH` (int, 24),
`fileTransfer.autoReminderCooldownH` (int, 48).

**Email** : `fileTransfer.fromAddress` (string),
`fileTransfer.notifyOnExpired` (bool, false).

> ⚠️ À vérifier au moment de l'implémentation : `SettingFieldDescriptor`
> supporte-t-il un type `secret` (masquage côté UI) ? Sinon, passer
> `R2_ACCESS_KEY_ID` et `R2_SECRET_ACCESS_KEY` via env vars uniquement
> et ne pas les déclarer dans l'enum.

## Liste transferts admin

Page `AdminTransfersApp.vue` :
- Table : reference, sender (email + name), status badge, # files, # recipients, # downloads, created, expires
- Filtres : status (multi-select), date range, sender (autocomplete user)
- Pagination
- Actions admin : voir détail, expire force, delete force

API endpoint : `GET /backend/file-transfer/api/admin/transfers?status=ready,expired&from=2026-01-01&to=2026-12-31&user=42&page=1&per_page=50`

```php
#[Route('/backend/file-transfer/api/admin/transfers', methods: ['GET'])]
#[IsGranted('file_transfer.admin')]
public function adminList(Request $request): JsonResponse
{
    $criteria = AdminTransfersCriteria::fromRequest($request);
    $paginator = $this->repository->paginateAdmin($criteria);
    return $this->json([
        'items' => array_map($this->serializer->serialize(...), iterator_to_array($paginator)),
        'total' => $paginator->count(),
        'page' => $criteria->page,
        'perPage' => $criteria->perPage,
    ]);
}
```

## Page stats

`StatsApp.vue` lit `FileTransferStatsService::getDashboard()` :

```php
final readonly class FileTransferStatsService
{
    public function __construct(
        private FileTransferRepository $transferRepo,
        private FileTransferStatsRepository $statsRepo,
    ) {}

    public function getDashboard(): array
    {
        $now = new DateTimeImmutable();
        $sixMonthsAgo = $now->modify('-6 months');

        return [
            'total' => [
                'transfers' => $this->transferRepo->countAll(),
                'transfersReady' => $this->transferRepo->countByStatus(Ready),
                'transfersExpired' => $this->transferRepo->countByStatus(Expired),
                'storageUsedBytes' => $this->transferRepo->totalStorageUsed(),
            ],
            'deletions' => $this->statsRepo->getOrInit(),
            'series' => [
                'transfersPerMonth' => $this->transferRepo->countPerMonth($sixMonthsAgo),
                'downloadsPerMonth' => $this->transferRepo->downloadsPerMonth($sixMonthsAgo),
            ],
        ];
    }
}
```

Graphs via `vue-chartjs` (déjà installé).

## Entité `FileTransferStats` (single-row)

Single-row table avec `id` = constant 1, contient les cumuls de
suppressions (pour mémoire post-soft-delete).

| Colonne | Type | Notes |
|---|---|---|
| `id` | int (constant 1) | PK, force `WHERE id=1` |
| `deletedTransfersCount` | int | incrément à chaque expire/delete |
| `deletedFilesCount` | int | idem |
| `deletedFilesSize` | bigint | bytes |
| `deletedRecipientsCount` | int | idem |
| `updatedAt` | timestamp | - |

Pas de DTO/Manager 5-couches sur cette entité - service simple
`FileTransferStatsRepository::increment($transfers, $files, $bytes, $recipients)`.

## Décisions ouvertes

- **Liste transferts admin** : pas de bouton "ré-envoyer email recipient" depuis l'admin V1. Si besoin → reuse du `recipient.md` endpoint avec permission `file_transfer.admin`.
- **Settings UI** : si le module Configuration n'a pas de support pour `type:secret` (champ avec masquage), passer les credentials R2 via env vars uniquement et masquer ces 2 settings dans l'UI.

## Tests obligatoires

- `GET /backend/file-transfer/api/admin/transfers` sans ADMIN → 403
- Liste : filtres + pagination + sort
- Stats : `getDashboard` retourne la bonne shape pour Vue
- Increment stats : `expire(transfer)` → `deletedTransfersCount` +1, `deletedFilesSize` += sum
