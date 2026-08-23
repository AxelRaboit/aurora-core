---
name: process_release
description: Processus de release aurora-core - CHANGELOG, tag, communication vers aurora-client et projets clients.
metadata:
  type: project
---

## Règle

### 1. Au fil des commits (continu)

Chaque feature/fix notable → noter une ligne sous `## [Unreleased]` dans
`CHANGELOG.md` à la racine d'aurora-core. Quatre sections :
- `### Ajouté` - nouvelles features
- `### Changé` - modifications comportement existant
- `### Dans aurora-client` - **ce que les projets clients devront faire**
  après `make aurora-update` pour cette version (tableau action / commande)
- `### Breaking changes` - si API publique cassée (AsAlias renommé, hook supprimé, etc.)

### 2. Au moment du release

```bash
# 1. Fermer [Unreleased] dans CHANGELOG.md
#    → remplacer "## [Unreleased]" par "## [X.Y.Z] - YYYY-MM-DD"
#    → ajouter un nouveau "## [Unreleased]" vide au-dessus

# 2. Commit du CHANGELOG
git add CHANGELOG.md
git commit -m "chore(release): bump X.Y.Z"

# 3. Tag + push (le Makefile crée le tag ET le pousse)
make tag VERSION=X.Y.Z

# 4. Mettre à jour aurora-client (template)
#    → appliquer ce que la section "Dans aurora-client" dit
#    → commit dans aurora-client avec "chore: sync to aurora-core X.Y.Z"
```

### 3. Côté projets clients (après déploiement du tag)

Le client ouvre `CHANGELOG.md` dans sa version actuelle d'aurora-core (vendor) :
```bash
cat vendor/axelraboit/aurora/CHANGELOG.md
```
Il lit la section **"Dans aurora-client"** de chaque version entre la sienne et
la cible, et applique les actions dans l'ordre (ascendant).

Puis il bumpe :
```bash
make aurora-update   # composer update axelraboit/aurora + tous les syncs
```

### 4. Numérotation SemVer

| Incrément | Quand |
|-----------|-------|
| **PATCH** `0.x.Y` | Bug fix, refacto interne, docs - aucune action côté client requise |
| **MINOR** `0.X.0` | Nouvelle feature non-cassante - section "Dans aurora-client" peut avoir des étapes optionnelles |
| **MAJOR** `X.0.0` | Breaking change API publique (renommage AsAlias, suppression hook, migration forcée) - section "Dans aurora-client" aura des étapes obligatoires |

Tant qu'on est en `0.x`, les MINOR peuvent inclure des breaking changes mineurs
(conventions de préparation avant la stabilité `1.0`).

## Pourquoi

Sans CHANGELOG avec section "Dans aurora-client" explicite, les projets clients
ne savent pas quoi faire manuellement après `make aurora-update` - ce qui ne se
sync pas automatiquement (services.yaml, nouveau privilège renommé, etc.) reste
invisible jusqu'à ce que ça plante.

## Comment l'appliquer

- Ouvrir `CHANGELOG.md` dès qu'une feature est terminée (pas en fin de release).
- La section "Dans aurora-client" = **uniquement les actions manuelles** que
  `make aurora-update` ne fait pas seul. Si sync-env/sync-makefile/sync-security
  gèrent, ne pas mentionner.
- Après chaque release, vérifier que `aurora-client` (template) est à jour en
  appliquant les mêmes actions - c'est le garant que la prochaine clone part propre.
