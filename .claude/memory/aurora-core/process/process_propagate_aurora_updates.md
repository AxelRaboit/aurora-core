---
name: process_propagate_aurora_updates
description: Après tout changement aurora-core mergé sur develop, propager aux projets consommateurs (push develop → make aurora-update). aurora-client = projet modèle, à bumper en premier. Liste des consommateurs tenue à jour dans le doc.
metadata:
  type: project
---

Quand une modif aurora-core est mergée sur `develop`, elle n'a **aucun effet
client tant qu'on n'a pas propagé**. Procédure (flux actuel, `dev-develop`) :

1. `git push origin develop` (les clients consomment `dev-develop` depuis
   GitHub → `composer update` tire le distant, pas le local).
2. Dans chaque projet consommateur : `make aurora-update` (composer update +
   installs + cache:clear + `migrate-f` + syncs + translation + build).
3. Vérifier avec **`make ft`** — il tourne bien sur aurora-client (phpstan,
   twig-cs, build, migrate-check). L'ancienne note « pas de tests → se
   contenter du build » était fausse et coûtait la moitié du filet.
4. Commiter le bump : `chore(deps): bump aurora-core to <sha>`.
5. Attendre la **CI du consommateur**. Une propagation n'est pas finie quand
   le bump est poussé, elle est finie quand son pipeline est vert.

**Why** : sans propagation, une feature livrée dans le bundle reste invisible
côté client ; et `composer update` tire le `develop` **distant**, donc oublier
le push = bumper l'ancien état.

**How to apply** : passer par le skill **`propagate`**, qui exécute tout ceci
et tient les deux garde-fous que la procédure écrite laisse à la vigilance —
refuser de démarrer sur une CI aurora-core rouge ou encore en cours, et
énoncer les migrations du range **avant** que `make aurora-update` ne les
applique tout seul. Ce fichier reste le résumé ; le skill est la forme
exécutable.

**How to apply** :
- **Bumper `aurora-client` en PREMIER** : c'est le **projet modèle/référence**
  (gabarit de `.claude/client_template/`), gardé épuré, sert de canari. Puis
  les autres consommateurs éventuels — il n'y en a aucun d'autre aujourd'hui.
- Si le bump contient une **migration** (`migrate-f` l'applique) → backup DB
  prod avant.
- **Liste des consommateurs + procédure détaillée** : doc
  `docs/aurora-core/dev/propagating_updates.md` (tenue à jour : ajouter une
  ligne au tableau à chaque nouveau projet). Ne pas dupliquer ici.

Releases taggées (CHANGELOG + `make tag` + SemVer) = **plus tard**, pas
maintenant ; flux esquissé dans [[process_release]]. Voir aussi
[[process_atomic_commits]] (commits par entité) et [[process_doc_audit_before_commit]].
