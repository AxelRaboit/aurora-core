---
name: Convention fichiers temporaires + Symfony Scheduler
description: Tout fichier tmp Aurora utilise un préfixe nommé et est nettoyé par CleanTempFilesHandler via le Scheduler toutes les heures
type: feedback
---

## Règle

Toute nouvelle tâche récurrente → **Symfony Scheduler** (`MainSchedule`), jamais un cron système externe.

Tout fichier temporaire Aurora → `tempnam(sys_get_temp_dir(), 'aurora_<module>_<role>_')` + suppression dans `finally` + préfixe déclaré dans `CleanTempFilesHandler::TMP_PREFIXES`.

## Pattern tâche récurrente

```php
// 1. Message (DTO vide)
final readonly class MonMessage {}

// 2. Handler
#[AsMessageHandler]
final readonly class MonHandler {
    public function __invoke(MonMessage $message): void { ... }
}

// 3. Enregistrement dans MainSchedule
->add(RecurringMessage::cron('0 4 * * *', new MonMessage()))
```

## Fichiers temporaires - convention

| Préfixe | Module | Nettoyé par |
|---|---|---|
| `aurora_pdfform_xfdf_` | PdfForm | `CleanTempFilesHandler` (> 30 min) |
| `aurora_pdfform_values_` | PdfForm | `CleanTempFilesHandler` (> 30 min) |
| `aurora_ssh_` | MountPoint | `CleanTempFilesHandler` (> 30 min) |

**Nouveau préfixe** → ajouter à `CleanTempFilesHandler::TMP_PREFIXES`.

## Fichiers générés protégés (PdfForm)

PDFs générés → `var/pdfform/YYYY-MM/REF.pdf` (hors `public/`).
Servis via `GET /backend/pdfform/documents/{id}/download` (auth requise).
**Ne pas utiliser MediaManager** pour des fichiers techniques internes → pollution médiathèque.

## Pourquoi

- Crash PHP avant `unlink()` → orphelins dans `/tmp` → nettoyage horaire automatique
- Fichiers techniques dans MediaManager → polluent `/backend/media/media` (expérience utilisateur dégradée)
- Scheduler > cron système : versionné, testable, pas de dépendance infra

## Références

- `src/Core/Scheduler/MainSchedule.php`
- `src/Core/Scheduler/MessageHandler/CleanTempFilesHandler.php`
- `docs/aurora-shared/scheduler.md`
