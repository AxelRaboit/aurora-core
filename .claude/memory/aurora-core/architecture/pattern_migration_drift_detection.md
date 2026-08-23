---
name: pattern-migration-drift-detection
description: Système 2-couches qui alerte le dev quand sa base dev a des migrations Doctrine en attente - banner Twig dans l'admin + warning CLI dans `make ft`.
metadata:
  type: project
---

## Règle

Aurora détecte automatiquement quand le **dev DB** est en retard sur le code
(migrations présentes dans `migrations/Version*.php` mais pas exécutées).
Deux surfaces de détection :

1. **Banner Twig** (`src/Core/templates/Core/backend/layout.html.twig`) - affiché en
   haut de chaque page admin **uniquement en env dev** quand des migrations
   sont en attente. Mention "X migration(s) en attente sur la base dev" +
   code `make migrate` à exécuter.
2. **CLI** (`make migrate-check`) - appelé automatiquement à la fin de
   `make ft`. Warning jaune loud + liste des migrations en attente +
   instruction. Silencieux si DB à jour.

## Pourquoi

Le `make ft` lance les migrations sur le **test DB** (via `make db-test`)
mais **jamais sur le dev DB**. Conséquence : un dev qui pull une branche avec
de nouvelles migrations passe ses tests sans s'en apercevoir, puis voit des
données stale dans le navigateur (ex : modale Privilèges qui affiche encore
`core.media.edit` parce que la JSONB du JSONB `core_users.privileges`
n'a pas été renommée).

Le banner est impossible à manquer pour qui ouvre l'admin. Le CLI couvre
le dev qui fait du back-office sans front (commits, refactors).

## Comment l'appliquer

**Côté dev** : si tu vois le banner ou le warning CLI :

```bash
make migrate     # applique toutes les migrations en attente
```

**Côté code** :

- `MigrationStatusChecker` (`src/Core/Migration/Service/`) - compare le
  nombre de fichiers `Version*.php` au COUNT(*) de
  `doctrine_migration_versions`. Renvoie `countPending(): int`.
- `MigrationStatusExtension` (`src/Core/Twig/`) - Twig function
  `aurora_pending_migrations()` exposée à tous les templates.
- Layout admin : `{% if app.environment == 'dev' and aurora_pending_migrations() > 0 %}`.
- Translations : `backend.migrations.pending.banner` dans
  `src/Core/Migration/translations/`.
- Makefile : `make migrate-check` (silent si OK, loud sinon), hooké
  dans `ft: fix && test && migrate-check`.

## Limites assumées

- Le check ne s'active **qu'en env dev**. En prod, on assume que CI/CD
  applique les migrations automatiquement avant le déploiement.
- Le compte se base sur le nom des fichiers, pas sur le contenu. Si
  quelqu'un édite une migration déjà exécutée (mauvaise pratique), le
  système ne le détecte pas.
- Performance : un `glob()` + `SELECT COUNT(*)` par requête admin en dev.
  Négligeable (< 1ms).

## Lieux clés

- Service : `src/Core/Migration/Service/MigrationStatusChecker.php`
- Twig ext : `src/Core/Twig/MigrationStatusExtension.php`
- Banner : `src/Core/templates/Core/backend/layout.html.twig` (autour de la
  banner IS_IMPERSONATOR)
- Makefile target : `migrate-check` (aurora-core + client_template)
- Translations : `src/Core/Migration/translations/messages.{fr,en}.yaml`
