# Convention : sécuriser backend ET frontend avec les privilèges

## Règle

**Tout privilège doit gate à 2 endroits**, jamais un seul :

1. **Côté serveur (PHP)** - l'autorité, c'est le seul gardien réel
2. **Côté client (Vue)** - UX, masquer les actions interdites

Les deux ensemble. Jamais l'un sans l'autre.

## 1. Backend : `#[IsGranted]` sur les routes

Au niveau **classe** pour le minimum requis (souvent `view`), au niveau
**méthode** pour les actions plus restrictives.

```php
#[Route('/backend/planning/plannings', name: 'backend_planning_plannings')]
#[IsGranted('planning.plannings.view')]                  // ← lecture
class PlanningsController extends AbstractController
{
    public function index(): Response { ... }            // hérite view

    #[IsGranted('planning.plannings.manage')]            // ← écriture
    public function create(Request $request): JsonResponse { ... }

    #[IsGranted('planning.plannings.manage')]
    public function update(...): JsonResponse { ... }

    #[IsGranted('planning.plannings.manage')]
    public function delete(...): JsonResponse { ... }
}
```

Si une action n'a pas de `#[IsGranted]` explicite, elle hérite du
niveau classe. Si la classe non plus, **n'importe qui authentifié peut
appeler la route** - bug de sécurité.

### Audit serveur

```bash
# Toutes les routes sans #[IsGranted] dans Module/Core
grep -rn "#\[Route" src/Core/ src/Module/ --include="*.php" -A2 \
  | grep -v "IsGranted" | grep -B1 "Route" | head -30
```

Toute route admin (`/backend/...`) doit être suivie d'un `IsGranted`
explicite.

## 1bis. Sidemenu : `requiredPrivilege` sur les `NavItem`

Aussi obligatoire. Sans ça, **un user sans la perm voit l'entrée dans
le sidemenu**, clique, se prend `Access Denied`. Friction inutile :
si la route est gated par `IsGranted('x.y.z')`, son `NavItem` doit
déclarer `requiredPrivilege: 'x.y.z'`.

```php
// XxxModule::getNavSections()
new NavItem(
    'backend_billing_tiers',
    'backend.nav.tiers',
    'users',
    requiredPrivilege: 'billing.tiers.view',          // ← obligatoire
    descriptionKey: 'backend.nav.tiers_description',
)
```

`ModuleRegistry::resolveItem()` filtre déjà via la perm - il suffit
de la déclarer. Pareil sur `getCatalogNavSections()` (la catalogue
dev panel doit refléter le même gating). Si l'`IsGranted` classe-level
du controller est `mod.entity.view`, c'est cette même clé qu'on met
sur le `NavItem`.

**Audit** :
```bash
# NavItems sans requiredPrivilege dans tous les modules
grep -E "new NavItem\(" src/Module/*/[A-Z]*Module.php \
  | grep -v "requiredPrivilege" | head -30
```

## 2. Frontend : `can()` via `usePrivileges`

```vue
<script setup>
import { usePrivileges } from "@/shared/composables/usePrivileges.js";

const { can } = usePrivileges();
const canManagePlannings = computed(() => can("planning.plannings.manage"));
const canManageEvents = computed(() => can("planning.events.manage"));
</script>

<template>
    <!-- Bouton de création - gated -->
    <AppButton v-if="canManagePlannings" variant="primary" v-on:click="openCreate()">
        {{ t("backend.plannings.new") }}
    </AppButton>

    <!-- Actions ligne - gated -->
    <AppIconButton v-if="canManagePlannings" color="rose" v-on:click="confirmDelete">
        <Trash2 />
    </AppIconButton>

    <!-- Modale Save / Delete - gated dans le footer -->
    <AppModalFooter>
        <AppButton v-if="!readOnly && canManageEvents" type="submit">
            {{ t("shared.common.save") }}
        </AppButton>
    </AppModalFooter>
</template>
```

`usePrivileges.can()` :
- Dev / Admin → toujours `true`
- User normal → vrai uniquement si la perm est dans la liste

Les flags `window.__isDev__` / `__isAdmin__` / `__privileges__` sont
posés par le layout Twig, miroir parfait du `ModulePermissionVoter`
serveur.

### Côté composables (logique JS)

Si une **action** est déclenchée hors d'un click direct (ex: drag-drop,
slot select dans un composant tiers), **passer la perm au composable**
et guard à l'intérieur :

```js
// useCalendar.js
export function useCalendar({ canManageEvents, ... }) {
    function isManageAllowed() {
        return canManageEvents ? canManageEvents.value : true;
    }

    function onSlotSelect(slot) {
        if (!isManageAllowed()) return;
        eventForm.openCreate(slot);
    }

    const options = computed(() => ({
        selectable: isManageAllowed(),
        editable: isManageAllowed(),
        // ...
    }));
}
```

Pareil pour FullCalendar `selectable` / `editable` : en plus de cacher
les boutons, on désactive nativement la sélection / drag-drop pour pas
laisser l'utilisateur croire qu'il peut faire l'action.

### Audit frontend

```bash
# Trouver les composants App* qui ne gate aucune action visible
grep -rL "usePrivileges\|can(" src/Core/assets/backend/ src/Module/*/assets/backend/ \
  --include="*App.vue"
```

Tout `*App.vue` admin avec un bouton de création/édition/suppression
doit avoir un `usePrivileges`.

## Pourquoi les 2

### Pourquoi backend (obligatoire)

C'est **la seule garantie**. Quelqu'un peut :

- Bypasser le frontend (curl, postman, devtools)
- Modifier le JS pour forcer un bouton à s'afficher
- Avoir un cache désynchronisé (privileges récemment révoqués)

Si seul le client gate, l'app est non-sécurisée. Le serveur **doit**
refuser les requêtes interdites.

### Pourquoi frontend (essentiel UX)

Sans ça :

- L'utilisateur clique → 403 → toast d'erreur générique → confus
- Les actions interdites sont visibles → impression d'app cassée
- Pas de signal visuel que telle ou telle fonctionnalité existe mais
  est restreinte (vs. n'existe pas du tout)

Le client guide, le serveur garde.

## Comment l'appliquer

### Pour un nouveau module CRUD avec privilèges

1. Déclarer les `NavPermission` dans `<Module>Module.php`.
2. **Backend** : `#[IsGranted('mod.entity.view')]` sur la classe
   contrôleur, `#[IsGranted('mod.entity.manage')]` sur les méthodes
   `create/update/delete`.
3. **Frontend** : `const { can } = usePrivileges()` dans le `<App>.vue`,
   computed `canManageX = computed(() => can("mod.entity.manage"))`,
   `v-if="canManageX"` sur tous les boutons d'action.
4. Si des composables manipulent des actions, leur passer
   `canManageX` (ref/computed) et guard à l'intérieur.
5. Ajouter les libellés dans
   `src/Module/<Module>/translations/messages.{fr,en}.yaml` (cf
   [`convention_privilege_translations.md`](convention_privilege_translations.md)).

### Pour un module existant qui n'a qu'un des deux

- Backend manque → audit grep + ajouter `#[IsGranted]`. Risque
  sécurité.
- Frontend manque → ajouter `usePrivileges` + `v-if="can(...)"` sur
  les boutons. Risque UX.

## Source

Convention validée le 2026-05-09 après audit du module Planning :
le backend était gated correctement, mais le frontend n'avait aucun
`can()`. Les boutons étaient visibles pour tous → l'utilisateur view-only
voyait Save/Delete et tombait sur des 403 au clic. Fix complet :
`usePrivileges` + `canManagePlannings` / `canManageEvents` propagés
dans `useCalendar`, `useResourceMode`, modales et toolbar. Project
servait déjà de référence.

## Voir aussi

- [`convention_privilege_translations.md`](convention_privilege_translations.md)
  - où placer les libellés des privilèges (YAML par module, format
  nested).
