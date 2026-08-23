# Convention : modales + confirmation de suppression

## Règle

### 1. AppModal - API à respecter

`AppModal` (`src/Core/assets/shared/components/overlay/AppModal.vue`) attend :

- `:show` (Boolean) - visibilité contrôlée par le parent
- `v-on:close` (event) - émis quand l'utilisateur veut fermer (ESC, clic overlay, bouton X)
- `:title` (String, optionnel) - header avec close button automatique
- `max-width` - `sm` / `md` / `lg` / `xl` / `2xl` / etc.

**Règle absolue sur les boutons** : dès qu'une modale contient des
boutons d'action (annuler, confirmer, sauvegarder…), ils vont
**toujours** dans `<template #footer><AppModalFooter>` - jamais en
inline à la fin du body. Le slot `#footer` est un footer sticky séparé
par un `border-t` avec la bonne mise en page responsive
(`flex-col sm:flex-row sm:justify-end`).

```vue
<AppModal
    :show="editModal.open"
    max-width="md"
    :title="editModal.entity ? t('edit') : t('new')"
    v-on:close="editModal.open = false"
>
    <form class="space-y-4" v-on:submit.prevent="submit">
        <!-- champs du formulaire -->
    </form>

    <template #footer>
        <AppModalFooter>
            <AppButton variant="ghost" size="md" v-on:click="editModal.open = false">
                <X class="w-3.5 h-3.5" :stroke-width="2" />
                {{ t("shared.common.cancel") }}
            </AppButton>
            <AppButton type="submit" variant="primary" size="md" :loading="editModal.saving">
                <Save class="w-3.5 h-3.5" :stroke-width="2" />
                {{ t("shared.common.save") }}
            </AppButton>
        </AppModalFooter>
    </template>
</AppModal>
```

**❌ NE PAS** utiliser `v-model:open` - ça ne marche pas, les modales
restent fermées sans erreur visible. C'est le piège classique.

**❌ NE PAS** mettre les boutons dans le body (`<div class="flex justify-end gap-2">`) -
ils doivent être dans `#footer`.

### 1bis. Variants AppButton pour actions destructives

Trois niveaux d'intensité - choisir selon le contexte :

| Variant | Utilisation | Exemple |
|---------|-------------|---------|
| `danger` (rose plein) | **Bouton final** dans une modale de confirmation, action immédiate | Confirm dans modale Delete |
| `danger-subtle` (rose teinté) | Bouton destructeur **qui ouvre une confirmation** (pas l'action finale) | « Supprimer » dans une modale d'édition |
| `<AppIconButton color="rose">` | Icône poubelle dans table/toolbar | Trash dans une row |

**Mauvais** : mettre `danger` plein partout - l'œil est saturé de rouge,
on ne distingue plus le bouton qui va effectivement supprimer (le
final) des boutons qui ouvrent un flow.

**Bon** : la cascade visuelle suit le degré d'engagement
(icône < subtle < danger plein).

### 2. Confirmation de suppression - toujours via modale

**❌ Jamais** `confirm("Êtes-vous sûr ?")` (alert JS native, dégueu et
pas accessible).

**✅ Toujours** une `AppModal` de confirmation avec un composable
`use<Entity>Delete.js` dédié.

#### Pattern composable

```js
// src/Module/<Module>/assets/<scope>/<plural>/composables/use<Plural>Delete.js
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/useRequest.js";

export function usePlanningDelete(plannings, deletePath) {
    const { t } = useI18n();
    const { request } = useRequest();

    const deletingPlanning = ref(null);

    async function confirmDelete() {
        if (!deletingPlanning.value) return;
        const data = await request(
            buildPath(deletePath, { id: deletingPlanning.value.id }),
        );
        if (!data?.success) return;

        plannings.value = plannings.value.filter(
            (planning) => planning.id !== deletingPlanning.value.id,
        );
        toast.success(t("shared.common.deleted"));
        deletingPlanning.value = null;
    }

    return { deletingPlanning, confirmDelete };
}
```

#### Pattern UI

```vue
<!-- Bouton qui ouvre la confirmation -->
<AppIconButton color="rose" :title="t('shared.common.delete')"
    v-on:click="deletingPlanning = planning">
    <Trash2 class="w-4 h-4" :stroke-width="2" />
</AppIconButton>

<!-- Modale de confirmation -->
<AppModal :show="!!deletingPlanning" max-width="sm" v-on:close="deletingPlanning = null">
    <p class="text-sm text-primary">
        {{ t("backend.plannings.delete_confirm", { name: deletingPlanning?.name ?? "" }) }}
    </p>

    <template #footer>
        <AppModalFooter>
            <AppButton variant="ghost" size="md" v-on:click="deletingPlanning = null">
                <X class="w-3.5 h-3.5" :stroke-width="2" />
                {{ t("shared.common.cancel") }}
            </AppButton>
            <AppButton variant="danger" size="md" v-on:click="confirmDelete">
                <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                {{ t("shared.common.delete") }}
            </AppButton>
        </AppModalFooter>
    </template>
</AppModal>
```

#### Clés de traduction

`backend.<plural>.delete_confirm` doit prendre un placeholder
`{name}` ou `{title}` pour rappeler quelle entité va être supprimée.

```yaml
plannings:
  delete_confirm: 'Supprimer le planning « {name} » ? Cette action est irréversible.'
```

## Pourquoi

- **UX** : `confirm()` natif est moche, pas thématisable, pas
  accessible (focus trap inexistant), pas testable, et bloque le main
  thread.
- **Cohérence** : tous les modules Aurora suivent ce pattern
  (Agencies, Project, Media, Forms, etc.). Tout nouveau module qui
  utiliserait `confirm()` casse l'expérience uniforme.
- **Variance d'édition** : la modale permet de styliser le bouton
  destructif (`variant="danger"`), nommer l'élément à supprimer dans le
  message, ajouter des notes ("tous les events seront aussi
  supprimés"), etc. Impossible avec `confirm()`.

## Comment l'appliquer

### Pour un nouveau module avec CRUD

1. Créer un composable `use<Plural>Delete.js` (cf snippet ci-dessus).
2. Dans le `<Plural>App.vue`, monter `deleting<Singular>` + `confirmDelete`.
3. Bouton trash → `v-on:click="deleting<Singular> = entity"`.
4. Modale de confirmation à la fin du template.
5. Clé i18n `backend.<plural>.delete_confirm` avec `{name}` ou `{title}`.

### Pour un module existant qui utilise `confirm()`

Refacto :
1. Créer le composable Delete.
2. Remplacer le `confirm()` par `deletingX = entity`.
3. Ajouter la modale.
4. Vérifier la clé i18n.

### Audit / détection

```bash
# Trouver les confirm() restants côté Vue
grep -rn "confirm(" src/Core/assets/ src/Module/*/assets/ --include="*.vue" --include="*.js" \
    | grep -v node_modules | grep -v ".test."
```

Devrait retourner 0 résultat dans `src/Module/` et `src/Core/assets/`.

## Source

Convention validée par l'utilisateur le 2026-05-08 sur le module
Planning : refacto pour aligner sur Agencies (qui suit déjà ce
pattern), ajout des composables `usePlanningDelete` + `useEventDelete`,
remplacement des deux `confirm()` natifs.
