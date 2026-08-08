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
3. Vérifier (`make test` / `make ft` ; aurora-client n'a pas de tests → la
   vérif = cache:clear + build OK pendant l'update).
4. Commiter le bump : `chore(deps): bump aurora-core to <sha>`.

**Why** : sans propagation, une feature livrée dans le bundle reste invisible
côté client ; et `composer update` tire le `develop` **distant**, donc oublier
le push = bumper l'ancien état.

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
