# Mémoire IA (Claude Code) dans un projet aurora-client

Ce document explique comment **organiser et entretenir la mémoire IA** dans
un projet client basé sur aurora-core. À lire si vous utilisez Claude Code
(ou un autre agent qui supporte une convention `.claude/memory/`) sur un
projet client en équipe.

---

## 1. Deux dossiers, deux rôles

Le projet client expose deux dossiers de mémoire (symlinkés depuis le
vendor par `make sync-claude-md`) :

| Dossier | Origine | Rôle |
|---|---|---|
| `.claude/memory/aurora-core/` | symlink vers `vendor/axelraboit/aurora/.claude/memory/aurora-core/` | conventions, décisions, pièges propres au bundle core. **Lecture seule** côté client. |
| `.claude/memory/aurora-client/` | symlink vers `vendor/axelraboit/aurora/.claude/memory/aurora-client/` | patterns d'extension côté consommateur (5 couches, overrides, pièges client). **Lecture seule** côté client. |

Ces deux dossiers sont **distribués via composer**. Une mise à jour
d'aurora-core via `make aurora-update` les met automatiquement à jour pour
tous les clients.

> **Conséquence importante** : vous ne devez **jamais éditer** les fichiers
> sous `.claude/memory/aurora-core/` ni `.claude/memory/aurora-client/`
> depuis votre projet client - ils sont écrasés à chaque sync. Pour
> contribuer, ouvrir une PR sur aurora-core directement.

---

## 2. Où mettre la mémoire **propre au projet client**

Créer un troisième dossier, **versionné dans le repo client** (pas un
symlink) :

```
.claude/memory/<nom-du-projet>/
├── MEMORY.md                              # index racine
├── backend/
│   ├── MEMORY.md                          # sous-index
│   └── pattern_<topic>.md
├── vue-backend/
│   └── …
├── architecture/
│   └── decision_<topic>.md
└── process/
    └── workflow_<topic>.md
```

Mirror exactement la structure d'aurora-core
(`backend/`, `vue-backend/`, `vue-frontend/`, `vue-transversal/`,
`architecture/`, `process/`, `preferences/`). Cette homogénéité aide
Claude à retrouver les bons fichiers.

**Règle de placement** (idem aurora-core) :
- PHP serveur → `backend/`
- Vue interface admin → `vue-backend/`
- Vue/Twig site public → `vue-frontend/`
- Vue/JS transversal → `vue-transversal/`

---

## 3. Format d'un fichier mémoire

Un fichier `.md` par sujet, nommé `<type>_<topic>.md`. Trois sections
obligatoires :

```md
# <Titre concis>

## Règle
Phrase impérative : "Toujours faire X." / "Ne jamais faire Y."

## Pourquoi
Le contexte / les contre-exemples qui ont motivé la règle.

## Comment l'appliquer
Étapes concrètes ou exemple de code minimal.
```

Pas besoin de frontmatter YAML - Claude Code lit la mémoire au plain text.
Garder court (< 80 lignes) et factuel.

---

## 4. Synchronisation locale (`make sync-claude-memory`)

`aurora-core` expose une cible `sync-claude-memory` qui recopie les
mémoires versionnées du repo vers le dossier user-level de Claude Code
(`~/.claude/projects/.../memory/`) pour qu'il les lise au chargement de
session.

**Côté projet client**, cette cible **n'existe pas par défaut** dans le
Makefile fourni par aurora-core (vérifier avec `grep sync-claude-memory
Makefile`). À ajouter dans **`Makefile.local`** si vous voulez un
équivalent - par exemple :

```makefile
sync-claude-memory: ## Sync project memory to user-level Claude Code memory
	@CLAUDE_DIR=$$(echo "$$HOME/.claude/projects/$$(pwd | sed 's|/|-|g')"); \
	mkdir -p "$$CLAUDE_DIR/memory"; \
	cp -r .claude/memory/<nom-du-projet>/* "$$CLAUDE_DIR/memory/"; \
	echo "✅ Memory synced to $$CLAUDE_DIR/memory"
```

> Ne jamais éditer `Makefile` directement - il est écrasé par
> `make sync-makefile` à chaque `make aurora-update` (cf.
> [`update_aurora.md`](update_aurora.md)). Toujours mettre les targets
> custom dans `Makefile.local`.

À lancer **après chaque ajout/modif** dans `.claude/memory/<nom-du-projet>/`.

---

## 5. Boucle d'hygiène mémoire (à appliquer)

Identique au cycle d'aurora-core (cf. `CLAUDE.md` section "Règle d'hygiène
mémoire") :

1. **Lire** les mémoires touchant la tâche en cours (ne pas se limiter au
   titre dans l'index - ouvrir les fichiers source).
2. **Vérifier la fraîcheur** : si une mémoire affirme qu'un fichier/classe/flag
   existe, vérifier dans le code courant avant de s'y fier.
3. **Corriger ou supprimer** les mémoires obsolètes (refacto, décision changée).
4. **Ajouter** une nouvelle mémoire dès qu'une convention émerge, qu'un
   piège est découvert, ou qu'une décision d'archi est prise.
5. **Ne pas dupliquer** : si la mémoire existe déjà dans
   `.claude/memory/aurora-core/` ou `aurora-client/`, **pointer** dessus
   plutôt que de la recopier.
6. **Synchroniser** : lancer la cible `sync-claude-memory` après tout
   ajout/modif.

À faire **à la fin de chaque tâche significative** : feature, refacto, fix
non-trivial, décision d'archi.

---

## 6. Ce qui n'a **pas** à être en mémoire

Pour rester maintenable, la mémoire **ne doit pas** contenir :

- Du contenu directement dérivable du code (signatures, listes de classes…).
- Du contenu déjà dans `docs/` (préférer un lien vers la doc).
- Des secrets ou des credentials (la mémoire est versionnée, partagée).
- Des notes éphémères "j'ai fait X ce matin" - git suffit.
- Des duplications de mémoires aurora-core / aurora-client (pointer dessus).

À l'inverse, **bons candidats** mémoire :

- Une convention de naming propre au projet ("toutes les FK projet utilisent
  `*_project_id`").
- Un piège métier découvert sur un cas réel ("ne jamais supprimer un user
  qui possède une `OcrJob` en cours - bypass-er via le manager").
- Une décision d'archi avec son contexte ("on a choisi X au lieu de Y parce
  que…").
- Un workflow d'équipe spécifique au projet.

---

## 7. CLAUDE.md projet vs CLAUDE.md core

| Fichier | Origine | Rôle |
|---|---|---|
| `CLAUDE.md` | symlink vendor (écrasé par `make sync-claude-md`) | charge automatiquement le contexte aurora-core à chaque session Claude Code |
| `CLAUDE.local.md` | versionné dans le repo client (jamais touché par sync) | **instructions custom du projet** : conventions internes, contacts d'équipe, raccourcis spécifiques |

Mettre vos instructions projet dans `CLAUDE.local.md`. Claude Code charge
les deux fichiers automatiquement.

---

## 8. Référence

- Section "Règle d'hygiène mémoire" du `CLAUDE.md` aurora-core
- [`../../aurora-core/dev/extending_aurora.md`](../../aurora-core/dev/extending_aurora.md)
  pour comprendre comment les mémoires aurora-client sont distribuées via
  composer
- Memories existantes :
  [`../../../.claude/memory/aurora-client/MEMORY.md`](../../../.claude/memory/aurora-client/MEMORY.md)
  (index des patterns d'extension prêts à l'emploi)
