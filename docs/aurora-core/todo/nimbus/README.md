# FileTransfer - nouveau module Aurora

Port du projet [`Nimbus`](https://github.com/AxelRaboit/nimbus) (Symfony 7 +
Vue 3, TUS, R2) vers un module Aurora Symfony, sur le même modèle que les
ports `Onyx → Notes` (cf. [`../notes/README.md`](../notes/README.md)) et
`Spendly → PersonalFinance` (cf. [`../spendly/README.md`](../spendly/README.md)).

Nimbus est une **application de transfert de fichiers sécurisé** :
upload TUS résumable (gros fichiers fragmentés), stockage R2/local, lien
personnel par destinataire, protection par mot de passe, expiration
configurable, notifications email, suivi des téléchargements.

## Nom du module - tranché

**`FileTransfer`** (proposé, mai 2026). Choisi pour :

- Domaine fonctionnel clair (pattern Aurora "nom de domaine", pas nom de
  produit comme `Editorial`, `Ecommerce`, `Notes`, `PersonalFinance` plutôt que
  `Spendly`).
- Distinct de `PersonalFinanceTransferService` (virements wallet-à-wallet
  internes au module finance perso) qui réutilise le mot "Transfer" dans
  un autre contexte.
- Alternatives écartées : `Transfer` (collision), `Share` (trop vague,
  recouvre déjà la sémantique "partage de notes"), `Nimbus` (nom de
  produit, contre la convention Aurora), `Drop` (mignon mais opaque).

Implications concrètes :

- Folder : `src/Module/FileTransfer/` et `src/Module/FileTransfer/assets/`
- Namespace : `Aurora\Core\Module\FileTransfer\`
- Entités préfixées : `FileTransfer`, `FileTransferFile`, `FileTransferRecipient`, `FileTransferStats`
- DB tables : `core_file_transfer_*`
- Sequences : `seq_core_file_transfer_<entity>_id` (cf. [convention extensibilité §1.1](../../dev/entity_extensibility_convention.md))
- Routes backend : `/backend/file-transfer/*`
- Routes publiques : `/t/{token}` (identique à Nimbus - voir [public_routes.md](public_routes.md))
- Twig namespace : `@FileTransfer/`
- Translations : `translations/file_transfer.<locale>.yaml`
- Storage local : `var/uploads/file-transfer/{transfer.token}/{filename}`
- Storage R2 : bucket Cloudflare, clé `{transfer.token}/{filename}` (préservée depuis Nimbus - voir [storage.md](storage.md))
- Commande console : `file-transfer:cleanup-tus`, `file-transfer:expire`, `file-transfer:send-reminders`

## Scope - inclus / exclu

> Contrainte explicite (mai 2026) : **on réutilise les mêmes ids R2 que
> Nimbus**. La clé R2 d'un fichier `{transfer.token}/{filename}` doit être
> identique entre l'ancien Nimbus et le nouveau module Aurora pour que les
> objets R2 existants restent accessibles sans déplacement. Voir
> [storage.md](storage.md) pour la stratégie de migration.

### Inclus (11 sous-domaines)

| Sous-domaine | Fichier | Statut |
|---|---|---|
| Transfer (entité racine + lifecycle) | [`transfer.md`](transfer.md) | ⏳ |
| TransferFile (fichier + ref storage) | [`transfer_file.md`](transfer_file.md) | ⏳ |
| Recipient (destinataire + tracking) | [`recipient.md`](recipient.md) | ⏳ |
| Storage abstraction (Local + R2 + migration) | [`storage.md`](storage.md) | ⏳ |
| TUS upload service | [`tus.md`](tus.md) | ⏳ |
| Validators (ext/MIME/zip-bomb) | [`validators.md`](validators.md) | ⏳ |
| Notifications email | [`notifications.md`](notifications.md) | ⏳ |
| Scheduler (cleanup/reminders/expire) | [`scheduler.md`](scheduler.md) | ⏳ |
| Public routes (`/t/{token}`) | [`public_routes.md`](public_routes.md) | ⏳ |
| Admin backend (liste, stats) | [`admin.md`](admin.md) | ⏳ |
| Frontend Vue (drop zone, manage, download) | [`frontend.md`](frontend.md) | ⏳ |

### Exclu (à NE PAS porter - Aurora couvre déjà)

| Fonctionnalité Nimbus | Couvert par | Note |
|---|---|---|
| Auth (login/register/reset/profile/locale) | `Module/Platform/Auth/` | Aurora a tout |
| **AccessRequest** (demande d'accès) | `Module/Platform/Auth/AccessRequest*` | **Existe déjà** (Entity + Manager + ViewBuilder admin) |
| Impersonation | `Module/Platform/` | OK |
| App settings (`ApplicationParameter`) | `Module/Configuration/` | Les paramètres Nimbus (max size, expiry, etc.) sont enregistrés comme settings Aurora |
| Plan Free/Pro/Stripe | (rien) | Pas porté. Limites définies par client via Configuration setting ou Voter custom |
| Démo seeder (`is_demo`, `nimbus:demo-seed`) | (rien) | Pas porté. Si utile → fixture standard côté client |
| Dev password (`/dev/...` shortcut auth) | (rien) | Pas porté. Aurora a son propre back-office |

### Plan tiers - non porté

Nimbus a `PlanService` + `User.plan` (Free/Pro/Trial) qui gate :
- Taille max d'un transfert
- Nombre de fichiers max
- Durée d'expiration max
- Nombre de destinataires max
- Accès à "Mes transferts"

Décision : Aurora n'embarque pas la notion de plan utilisateur. Le bundle
expose des **settings Configuration** (`fileTransfer.maxSizeMb`,
`fileTransfer.maxFiles`, etc.) avec des valeurs par défaut conservatrices.
Si un client veut un système de plans, il étend `Voter` ou
`SettingProvider` pour résoudre dynamiquement les limites par user.

Voir [admin.md §Settings](admin.md) pour la liste des settings exposés.

## Architecture cible

### Layout `src/Module/FileTransfer/`

```
src/Module/FileTransfer/
├── FileTransferModule.php                 # ModuleInterface impl + NavItems
├── FileTransferContext.php                # isEnabled + can(...) façade
├── Transfer/                              # entité racine
│   ├── Entity/                            # FileTransfer (Interface + Abstract + concrete)
│   ├── Dto/                               # 4-fichiers (Interface, Input, FactoryInterface, Factory)
│   ├── Manager/                           # FileTransferManager (hooks createX + applyInput + audit)
│   ├── Repository/                        # FileTransferRepository (étend ResolveTargetEntityRepository)
│   ├── Serializer/                        # FileTransferSerializer
│   ├── Enum/                              # FileTransferStatusEnum
│   └── Controller/Backend/                # FileTransferController (admin)
├── TransferFile/                          # fichiers d'un transfer
│   ├── Entity/                            # FileTransferFile
│   ├── Dto/  Manager/  Repository/  Serializer/
│   └── Enum/                              # FileTransferStorageBackendEnum (local/r2)
├── Recipient/                             # destinataires
│   ├── Entity/                            # FileTransferRecipient
│   ├── Dto/  Manager/  Repository/  Serializer/
│   └── Controller/Backend/                # gestion membres + reminders
├── Storage/                               # abstraction backend
│   ├── StorageAdapterInterface.php
│   ├── LocalStorageAdapter.php            # var/uploads/file-transfer/{token}/{filename}
│   ├── R2StorageAdapter.php               # bucket R2 - clés identiques Nimbus
│   ├── StorageManager.php                 # build key, route adapter
│   └── BinaryFileServer/                  # path-traversal guard + X-Sendfile (réutiliser Aurora\Core\Storage\BinaryFileServer ?)
├── Tus/                                   # protocole TUS résumable
│   ├── TusUploadService.php
│   ├── TusUploadServiceInterface.php
│   └── Controller/                        # TusController public (POST/PATCH/HEAD/DELETE /tus[/{key}])
├── Validator/                             # extension/MIME/zip-bomb
│   ├── TransferFileValidator.php
│   └── Constraint/                        # AllowedFileType, ZipContentSafe
├── Notification/                          # bridge vers Aurora Notification module
│   ├── FileTransferNotifier.php
│   └── FileTransferNotifierInterface.php  # AsAlias-able
├── Scheduler/                             # async jobs Symfony Messenger
│   ├── Message/                           # CleanupExpiredTransfersMessage, SendRemindersMessage, CleanupTusOrphansMessage
│   └── MessageHandler/                    # ⚠ pas de Schedule séparé - les RecurringMessage::cron(...) sont ajoutés dans Aurora\Core\Scheduler\MainSchedule
├── Stats/                                 # TransferStats single-row
│   ├── Entity/  Repository/  Service/     # FileTransferStatsService
│   └── Controller/Backend/                # admin stats view
├── Frontend/                              # façade publique (route /t/{token})
│   ├── Controller/                        # PublicTransferController
│   └── ViewBuilder/                       # PublicTransferViewBuilder
├── Setting/                               # Configuration tab provider (cf. Notes/Markdown/Setting/)
│   ├── FileTransferSettingEnum.php        # cases avec getKey/getType/getLabel/getDefaultValue
│   └── FileTransferConfigurationTabProvider.php  # implements ConfigurationTabProviderInterface
└── translations/                          # file_transfer.{fr,en,es,de}.yaml
```

### Frontend Vue

```
src/Module/FileTransfer/assets/backend/
├── new-transfer/                          # NewTransferApp.vue (drop zone + TUS + formulaire)
│   └── composables/useTusUpload.js
├── my-transfers/                          # MyTransfersApp.vue (liste user)
├── manage/                                # ManageTransferApp.vue (recipients + QR + reminders)
├── admin-transfers/                       # AdminTransfersApp.vue (liste tous)
└── stats/                                 # StatsApp.vue (deletion metrics)

src/Module/FileTransfer/assets/frontend/
├── public/                                # PublicTransferApp.vue (page /t/{token})
│   ├── password-unlock/
│   ├── download/
│   └── components/FileRow.vue
```

Chaque page suit la **convention 5-couches** Sylius-style
([`../../dev/entity_extensibility_convention.md`](../../dev/entity_extensibility_convention.md)) :
Interface + Abstract + concrete non-`final`, DTO non-`final` + Factory
`#[AsAlias]`, Manager non-`final` + hooks `protected`, Serializer non-`final`.

## Décisions structurelles transverses

### 1. Tokens cryptographiques - préservés tels quels depuis Nimbus

Trois colonnes token sur `FileTransfer` :
- `token` (varchar 64, unique) - lien public/recipient `/t/{token}`
- `ownerToken` (varchar 64, unique) - lien de gestion `/manage/{ownerToken}`
- `reference` (varchar 9, unique) - code court lisible affiché en UI

Génération : `bin2hex(random_bytes(32))` pour les longs, `bin2hex(random_bytes(4))` (slug humain) pour reference.

**Pour la migration** : les valeurs existantes de nimbus sont importées
telles quelles (pas de régénération) → les liens email déjà envoyés
restent valides.

### 2. Storage R2 - clés identiques à Nimbus

Formule : `{transfer.token}/{filename}` où `filename` est la valeur
randomisée stockée dans `FileTransferFile.filename` (préfixe 8-byte hex +
`_` + basename original).

Cf. [storage.md](storage.md) pour la stratégie de migration complète :
import DB, conservation token + filename, validation accessibilité R2.

### 3. TUS protocol - pas de queue Aurora, écriture filesystem comme Nimbus

Reprise du pattern Nimbus : `tus-php` côté serveur, chunks écrits dans
`var/uploads/file-transfer/tus_tmp/`, métadonnées en `var/uploads/file-transfer/tus_cache/`
via `TusFileStore`. Finalize = move atomique chunk → backend actif.

Pas de bascule sur Messenger pour TUS - overkill (protocole HTTP synchrone par chunk).
Voir [tus.md](tus.md).

### 4. Notifications - bridge sur Aurora\Core\Notification (confirmé)

Pas de port direct de `TransferNotifier` Nimbus (qui dispatche un
`EmailQueueMessage` custom). On utilise l'infra Aurora confirmée :
`Aurora\Core\Notification\NotificationManagerInterface` + entité
`Notification` (déjà branchée sur `AppNotificationsBell.vue` côté
backend).

Trois événements :
- `transfer.ready` → recipients par **email uniquement** (recipients
  pas forcément users Aurora)
- `transfer.downloaded` → owner : **email + in-app** (badge bell)
- `transfer.reminder` → recipients par **email uniquement**

Symfony Mailer + `MessageBusInterface` pour le dispatch async.

Voir [notifications.md](notifications.md).

### 5. Scheduler - réutiliser MainSchedule global

⚠️ **Aurora a UN seul schedule** : `Aurora\Core\Scheduler\MainSchedule`
(`#[AsSchedule('main')]`). Les modules y ajoutent leurs
`RecurringMessage::cron(...)` directement. Pas de schedule séparé par
module.

3 messages FileTransfer à ajouter dans `MainSchedule::getSchedule()` :
- `CleanupExpiredTransfersMessage` (`* * * * *`)
- `SendRemindersMessage` (`* * * * *`)
- `CleanupTusOrphansMessage` (`*/5 * * * *`)

Chaque handler est idempotent. Commandes console miroirs pour
debugging/cron-manuel.

Voir [scheduler.md](scheduler.md).

### 6. Storage convention - `var/uploads/file-transfer/`

Conforme à la [convention storage Aurora](../../../.claude/memory/aurora-shared/convention_storage_var_uploads.md) :
- Pas dans le document root
- Servi via `/uploads/{path}` ou route plus spécifique
  `/t/{token}/download` qui passe par `Aurora\Core\Storage\BinaryFileServer`
  pour le path-traversal guard + X-Sendfile

Storage layout :
```
var/uploads/file-transfer/
├── transfers/{token}/{filename}        # backend Local : fichiers permanents
├── tus_tmp/{uploadKey}                 # chunks TUS en cours
└── tus_cache/{uploadKey}.cache         # métadonnées TUS (file_path, name, size, …)
```

### 7. i18n - 4 langues comme Nimbus

`fr`, `en`, `es`, `de`. Reprendre les YAML Nimbus
(`translations/messages.<locale>.yaml`) et les couler dans
`translations/file_transfer.<locale>.yaml` avec sous-namespace
`file_transfer.transfer.*`, `file_transfer.recipient.*`, etc.

### 8. Plan de migration depuis Nimbus

Étapes (cf. [storage.md §Migration](storage.md)) :

1. Module FileTransfer implémenté à 100% (lecture + écriture nouvelle), tests verts
2. Side-by-side : Aurora module pointe sur le **même bucket R2** que Nimbus (credentials identiques)
3. Dump SQL Nimbus → script PHP de transformation → INSERT dans tables `core_file_transfer_*` (préservation `token`, `ownerToken`, `reference`, `filename`)
4. Vérification : pour chaque `FileTransferFile` importé, exists en R2 (commande dédiée `file-transfer:verify-r2`)
5. Cutover DNS/proxy : redirection `nimbus.tld/t/*` → `aurora.tld/t/*`
6. Décommissionnement Nimbus

## Précédents à respecter

- **Port `Onyx → Notes`** (🟢 terminé) - décisions DTO/Manager/Serializer
- **Port `Spendly → PersonalFinance`** (⏳ en cours) - pattern de port d'app
  Symfony → module Aurora avec entités préfixées
- **Convention 5-couches** - [`../../dev/entity_extensibility_convention.md`](../../dev/entity_extensibility_convention.md)

## Ordre d'exécution recommandé

Dépendances dures notées entre crochets `[dep: X]`.

1. **Storage abstraction** [aucune] - interface + Local + R2 + StorageManager + tests
2. **Transfer + TransferFile entities + DTO + Manager + Serializer** [dep: Storage]
3. **Recipient entity + DTO + Manager** [dep: Transfer]
4. **TUS upload service + TusController** [dep: Storage] - utilisable seul (upload sans finaliser de Transfer encore)
5. **Validators (file type + zip-bomb)** [dep: aucune]
6. **Settings Configuration (max size, expiry, etc.)** [dep: aucune]
7. **Notifications (notifier + 3 events)** [dep: Transfer + Recipient]
8. **Scheduler (cleanup expired + reminders + cleanup TUS)** [dep: Transfer + Notifications]
9. **Frontend public `/t/{token}` (Vue + controller)** [dep: Transfer + Storage]
10. **Frontend backend "Nouveau transfert" (Vue + TUS client + API create/finalize)** [dep: TUS + Transfer + Recipient]
11. **Frontend backend "Mes transferts" + "Manage"** [dep: 10]
12. **Admin (liste tous + stats)** [dep: 11]
13. **Stats entity + dashboard** [dep: 12]
14. **Migration script depuis Nimbus** [dep: tout le reste]

## Convention de mise à jour de ce TODO

- Une entrée par sous-domaine dans le tableau ci-dessus, statut ⏳ → 🟡 → 🟢
- À chaque sous-domaine terminé : commit atomique, statut 🟢 dans le
  tableau, contenu du fichier remplacé par "✅ Terminé, voir
  `src/Module/FileTransfer/<Section>/`"
- Si une décision structurelle change → mettre à jour ce README + une
  mémoire `.claude/memory/aurora-core/decision_<topic>.md`
