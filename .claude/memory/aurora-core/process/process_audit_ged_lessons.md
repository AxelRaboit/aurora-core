---
name: process_audit_ged_lessons
description: Leçons tirées de l'audit complet du module GED - patterns récurrents à vérifier sur tout nouveau module
metadata:
  type: feedback
---

## Règle

À la fin de chaque module significatif, faire un audit minutieux en vérifiant ces points critiques dans l'ordre.

## Pourquoi

L'audit du module GED a identifié 7 violations qui auraient pu passer inaperçues sans relecture systématique.

## Checklist d'audit par module

### Entités
- [ ] `Abstract<Name>` a bien `TimestampableTrait` + `#[ORM\HasLifecycleCallbacks]` - **même pour les entités de référence simples** (tags, folders). Exception légitime : entités vraiment immuables comme `DocumentVersion` (createdAt seulement).
- [ ] L'`Interface` expose **tous les setters** utilisés dans le Manager (`setDocument`, `setFile`, `setVersionNumber`, etc.) - pas seulement les getters.

### Managers
- [ ] Chaque classe instanciée dans le manager a un hook `protected create<X>(): <X>Interface` - **jamais `new Xxx()` directement**.
- [ ] Les nouvelles méthodes publiques (ex: `move()`, `reorder()`) sont déclarées dans l'interface.

### Serializers
- [ ] Tout serializer a une **interface** + `#[AsAlias]`, même pour les sub-entités "internes" (`DocumentVersionSerializer`). Sans ça, les clients ne peuvent pas le substituer.
- [ ] Le controller injecte l'interface (pas la classe concrète).

### Vue / JS
- [ ] **Aucun `fetch` brut** : toujours `useRequest` (gestion toast, loading, erreur automatique). Vérifier les composables de drag-and-drop et autres logiques HTTP custom.
- [ ] **Aucun `<button>`, `<input>`, `<select>` brut** : toujours `AppButton`, `AppIconButton`, `AppInput`, etc. - y compris les toggles d'arbre collapsible et les boutons reset.

### Traductions
- [ ] Les clés `backend.modules.xxx` sont bien sous le nœud YAML `backend > modules`, pas sous un nœud top-level homonyme (ex: `ged > modules` au lieu de `backend > modules`).

## Comment l'appliquer

Avant de commiter un module complet :
1. `grep -r "new [A-Z]" src/Module/<M>/` - vérifier qu'aucun `new Xxx()` n'échappe aux hooks
2. `grep -rn "fetch(" src/Module/<M>/assets/` - vérifier l'absence de fetch bruts
3. `grep -rn "<button\|<input\|<select" src/Module/<M>/assets/` - vérifier l'absence d'HTML brut
4. Vérifier que tous les serializers du module ont une interface
5. Vérifier la structure YAML des traductions (chemin `backend.modules.*` vs nœud top-level accidentel)
