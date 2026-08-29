---
name: process-doc-audit-before-commit
description: Avant chaque commit, vérifier que les docs/mémoires qui parlent du sujet touché sont encore exactes (ou les mettre à jour dans le même commit). Vaut dans les deux sens : code ↔ doc.
metadata:
  type: feedback
---

# Convention : audit docs/mémoires avant chaque commit

## Règle

**Avant chaque commit**, pour chaque fichier modifié, vérifier si une doc
sous `docs/` ou une mémoire sous `.claude/memory/` mentionne le sujet
touché. Si oui, vérifier que les snippets et affirmations sont **toujours
exacts** contre le code actuel. Mettre à jour dans le **même commit**
(préférable, atomicité) ou dans un commit `docs:` qui suit immédiatement.

Vaut **dans les deux sens** :
- **Code modifié** → audit des docs qui le décrivent
- **Doc/mémoire modifiée** → vérifier qu'aucune autre doc ne la
  référence avec un chemin obsolète (rename, suppression)

## Pourquoi

**Why:** En mai 2026, on a découvert que `add_module.md` (core + client)
et `extend_entity.md` étaient **massivement obsolètes** :
- Signatures `NavItem`/`NavSection` cassées (params renommés/ajoutés)
- `ModuleInterface` méthode `getCatalogNavSections()` manquante
- Namespaces réorganisés (`Aurora\Core\Module\NavItem` → `Aurora\Core\Module\Nav\NavItem`)
- 5 patterns ajoutés au code (toggles, Context, FrontendDescriptor,
  ConfigurationTabProvider, ModuleAccessChecker) **jamais documentés**
- Convention dossier `admin/` → `backend/` non répercutée

Conséquence : un dev qui aurait suivi la doc aurait écrit du code qui ne
compile pas. Réécritures réactives = **3 commits dédiés** pour rattraper
le retard. **Coût bien moindre si maintenu au fil de l'eau**.

**How to apply:**

### 1. Heuristique pour identifier les docs concernées

Avant `git commit`, sur les fichiers staged :

```bash
# Lister les noms saillants (classes, méthodes publiques, configs, paths)
git diff --cached --name-only

# Pour chaque nom saillant, grep dans docs/ et .claude/memory/
grep -rn "<ClassName>\|<methodName>\|<config_key>" docs/ .claude/memory/
```

Signaux qui doivent **déclencher l'audit** :
- Renommage de classe/interface/méthode publique → toutes les docs qui
  la nomment sont à vérifier
- Changement de signature (ajout/retrait/renommage de param) → les
  snippets de code dans les docs sont probablement faux
- Changement de namespace → toutes les `use` dans les snippets cassent
- Ajout d'une nouvelle interface marker / pattern récurrent → mérite
  d'être documenté (ne pas attendre que ce soit utilisé 5 fois)
- Renommage/suppression de fichier doc/mémoire → grep les références

### 2. Décision : même commit vs commit séparé

| Cas | Action |
|---|---|
| Modif code + doc qui décrit ce code | Commit atomique unique (préférable) |
| Plusieurs docs touchées par un même refacto | Un seul commit avec tout (atomicité) |
| Refacto déjà commité, on s'aperçoit après | Commit `docs:` immédiat (pas attendre) |
| Audit révèle drift cross-cutting (patterns jamais documentés) | Commit `docs:` dédié, structuré |

### 3. Sens inverse : doc modifiée

Quand on **rename** ou **supprime** une doc :

```bash
# Avant de supprimer / renommer
grep -rn "<old_filename>" docs/ .claude/memory/ CLAUDE.md
```

Mettre à jour les références dans le même commit (cf. consolidation
`extend_module.md` du 17 mai 2026 - 12 références à ajuster).

### 4. Échappatoire

**Aucune.** Si on touche du code documenté, on touche la doc. Si un cas
oblige à différer (urgence prod, rollback nécessaire), créer **une
tâche** (`docs: audit X after fix`) plutôt que d'oublier.

## Targets / outils utiles

```bash
# Recherche de références à un symbole
grep -rn "<NomClasse>\|<methodName>" docs/ .claude/memory/

# Lister les docs récemment modifiées (pour repérer celles à risque)
git log --oneline --since="3 months ago" -- docs/

# Détecter des liens morts dans les docs (à intégrer dans CI plus tard ?)
find docs -name "*.md" -exec grep -Hn "\](.*\.md)" {} \;
```

## Source

Convention demandée par l'utilisateur le 17 mai 2026 après 3 commits
consécutifs de rattrapage doc (`c2ffaa01`, `28d2b44b`, `b217cfad`) qui
ont révélé un drift massif sur `add_module.md` et `extend_entity.md` -
docs jamais maintenues au rythme de l'évolution du code.

Application **systématique** désormais. Lié à
[[process_make_ft_before_commit]] : même esprit (audit + fix avant
commit, jamais après).
