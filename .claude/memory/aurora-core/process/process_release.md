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

Depuis le 29/08/2026 la release est **automatique** : elle est publiée par
`.github/workflows/release.yml` au push sur `master`. Il n'y a plus de
`make tag` à lancer.

```bash
# 1. Sur develop : clore [Unreleased] dans CHANGELOG.md
#    → remplacer "## [Unreleased]" par "## [X.Y.Z] - AAAA-MM-JJ"
#    → laisser un "## [Unreleased]" vide au-dessus
git add CHANGELOG.md && git commit -m "chore(release): 0.6.1"

# 2. Ouvrir la PR develop → master, la faire relire, merger
gh pr create --base master --head develop --title "release 0.6.1"
```

Le merge fait le reste : le workflow lit le numéro dans la première section
close du changelog, crée le tag `vX.Y.Z` et publie la release avec cette
section en corps.

**Le numéro vit dans le changelog, pas dans le workflow.** C'est ce qui fait
que la relecture de la PR est aussi la relecture du numéro de version et des
notes de release. Un merge sans section close ne publie rien et l'écrit dans
les logs du workflow, plutôt que de publier une version au hasard.

**`master` est protégée** : pas de push direct, la PR est le seul chemin.

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
