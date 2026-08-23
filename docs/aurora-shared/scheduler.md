# Symfony Scheduler - tâches récurrentes Aurora

Aurora utilise **Symfony Scheduler** (`symfony/scheduler`) pour toutes les tâches automatisées récurrentes. Pas de cron système, pas de Supervisor séparé - tout est géré dans le worker PHP existant.

---

## Architecture

```
MainSchedule (src/Core/Scheduler/MainSchedule.php)
  │
  ├── * * * * *    → PublishScheduledPostsMessage
  ├── 0 3 * * *    → PurgeTrashedPostsMessage
  └── 0 * * * *    → CleanTempFilesMessage
```

Chaque tâche suit le pattern **Message + Handler** de Symfony Messenger :

```
src/Core/Scheduler/
  MainSchedule.php              ← enregistre toutes les tâches
  Message/
    CleanTempFilesMessage.php   ← DTO vide, identifie la tâche
  MessageHandler/
    CleanTempFilesHandler.php   ← logique de nettoyage
```

Les modules métier ont leurs propres messages dans leur namespace :
```
src/Module/Editorial/Post/
  Message/PurgeTrashedPostsMessage.php
  MessageHandler/PurgeTrashedPostsHandler.php
```

---

## Ajouter une tâche récurrente

### 1. Créer le message (DTO vide)

```php
// src/Core/Scheduler/Message/MonNettoyageMessage.php
// ou src/Module/MonModule/Message/MonNettoyageMessage.php

final readonly class MonNettoyageMessage {}
```

### 2. Créer le handler

```php
#[AsMessageHandler]
final readonly class MonNettoyageHandler
{
    public function __construct(
        private LoggerInterface $logger,
        // ... dépendances
    ) {}

    public function __invoke(MonNettoyageMessage $message): void
    {
        // logique de nettoyage
        $this->logger->info('MonNettoyage: {count} éléments traités.', ['count' => $n]);
    }
}
```

### 3. Enregistrer dans `MainSchedule`

```php
// src/Core/Scheduler/MainSchedule.php
->add(
    RecurringMessage::cron('0 4 * * *', new MonNettoyageMessage()),
)
```

**Syntaxe cron** : `minute heure jour mois jour_semaine`  
Exemples : `* * * * *` (chaque minute), `0 3 * * *` (3h du matin), `0 * * * *` (toutes les heures)

---

## Nettoyage des fichiers temporaires (`CleanTempFilesMessage`)

Lance toutes les heures. Couvre :

| Cible | Critère de suppression |
|---|---|
| `/tmp/aurora_pdfform_xfdf_*` | Fichier > 30 min (crash pdftk) |
| `/tmp/aurora_pdfform_values_*` | Fichier > 30 min (crash Node.js) |
| `/tmp/aurora_ssh_*` | Fichier > 30 min (crash MountPoint) |
| `var/pdfform/**/*.pdf` | PDF sans `PdfDocument` en base (orphelin) |

**Ajouter un nouveau préfixe tmp** : ajouter à `CleanTempFilesHandler::TMP_PREFIXES`.

**Convention** : tout fichier temporaire Aurora doit :
1. Utiliser un préfixe `aurora_<module>_<role>_` pour `tempnam()`
2. Être supprimé dans un bloc `finally` après usage
3. Avoir son préfixe déclaré dans `TMP_PREFIXES` pour le nettoyage automatique

---

## Tâches existantes

| Message | Fréquence | Description |
|---|---|---|
| `PublishScheduledPostsMessage` | Chaque minute | Publie les posts avec `scheduledAt` passé |
| `PurgeTrashedPostsMessage` | 3h du matin | Supprime les posts dans la corbeille depuis N jours (paramètre admin) |
| `CleanTempFilesMessage` | Toutes les heures (H:00) | Nettoie les fichiers temporaires orphelins (voir tableau ci-dessus) |
| `RecoverStuckOcrJobsMessage` | Toutes les heures (H:30) | Marque comme `failed` les jobs OCR bloqués en `extracting`/`parsing` depuis > 60 min |

---

## Worker

Le scheduler tourne via le worker `scheduler_main` :

```bash
# Dev
php bin/console messenger:consume async scheduler_main

# Prod (systemd)
# Voir docs/aurora-client/deployment/worker_systemd.md
```

Le worker doit être **redémarré après chaque déploiement** pour prendre en compte les nouvelles tâches.
