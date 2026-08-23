---
name: convention_modal_and_confirmation
description: 'CRITIQUE - avant d''écrire un delete dans un Vue : JAMAIS `confirm()` natif. TOUJOURS AppModal + useDelete composable. Erreur récurrente Claude (fix Sep 2025, fix Mai 2026 sur PersonalFinance) - lire AVANT toute action delete/destructive en Vue.'
metadata:
  type: feedback
---

> ⛔ **STOP - Si tu es sur le point de taper `confirm(`, `alert(`, ou `prompt(`
> dans un fichier Vue/JS Aurora, RELIS cette mémoire d'abord.**
>
> Erreur **récurrente** : Claude utilise `confirm()` natif par défaut pour les
> deletes, alors qu'Aurora a un pattern `AppModal + useDelete` documenté
> depuis longtemps. Refixé en mai 2026 sur les 3 Vue du module
> PersonalFinance (wallets, categories, transactions). Si tu lis ça en
> écrivant un nouveau CRUD, applique le pattern directement - n'attends pas
> que l'utilisateur te le signale.

## Règle

### 1. AppModal - API à respecter

`AppModal` attend :
- `:show` (Boolean) - visibilité contrôlée par le parent
- `v-on:close` (event) - émis quand l'utilisateur veut fermer (ESC, clic overlay, bouton X)
- `:title` (String, optionnel) - header avec close button automatique
- `max-width` - `sm` / `md` / `lg` / `xl` / `2xl`
- `:close-on-overlay` (Boolean, default `true`) - passer `false` pour les
  **modales avec formulaire**. ESC + bouton X ferment toujours, mais
  un clic dans le backdrop noir n'efface plus la saisie en cours.
  Confirmations Yes/No : garder le défaut `true` (overlay click = cancel).

**Règle absolue** : les boutons d'action vont **toujours** dans `<template #footer><AppModalFooter>` - jamais inline dans le body.

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
                {{ t("shared.common.cancel") }}
            </AppButton>
            <AppButton type="submit" variant="primary" size="md" :loading="editModal.saving">
                {{ t("shared.common.save") }}
            </AppButton>
        </AppModalFooter>
    </template>
</AppModal>
```

**Piège classique** : ne pas utiliser `v-model:open` - ça ne marche pas, les modales restent fermées sans erreur visible.

### 1bis. Variants AppButton pour actions destructives

| Variant | Utilisation |
|---------|-------------|
| `danger` (rose plein) | Bouton **final** dans une modale de confirmation |
| `danger-subtle` (rose teinté) | Bouton destructeur qui **ouvre** une confirmation |
| `<AppIconButton color="rose">` | Icône poubelle dans table/toolbar |

### 2. Confirmation de suppression - toujours via modale

**Jamais** `confirm("Êtes-vous sûr ?")`. **Toujours** une `AppModal` de confirmation avec un composable `use<Entity>Delete.js` dédié.

#### Pattern composable

```js
// use<Plural>Delete.js
export function use<Plural>Delete(items, deletePath) {
    const { t } = useI18n();
    const { request } = useRequest();
    const deletingItem = ref(null);

    async function confirmDelete() {
        if (!deletingItem.value) return;
        const data = await request(
            buildPath(deletePath, { id: deletingItem.value.id }),
        );
        if (!data?.success) return;

        items.value = items.value.filter((item) => item.id !== deletingItem.value.id);
        toast.success(t("shared.common.deleted"));
        deletingItem.value = null;
    }

    return { deletingItem, confirmDelete };
}
```

#### Pattern UI

```vue
<!-- Bouton qui ouvre la confirmation -->
<AppIconButton color="rose" :title="t('shared.common.delete')"
    v-on:click="deletingItem = item">
    <Trash2 class="w-4 h-4" :stroke-width="2" />
</AppIconButton>

<!-- Modale de confirmation -->
<AppModal :show="!!deletingItem" max-width="sm" v-on:close="deletingItem = null">
    <p class="text-sm text-primary">
        {{ t("backend.<plural>.delete_confirm", { name: deletingItem?.name ?? "" }) }}
    </p>
    <template #footer>
        <AppModalFooter>
            <AppButton variant="ghost" size="md" v-on:click="deletingItem = null">
                {{ t("shared.common.cancel") }}
            </AppButton>
            <AppButton variant="danger" size="md" v-on:click="confirmDelete">
                {{ t("shared.common.delete") }}
            </AppButton>
        </AppModalFooter>
    </template>
</AppModal>
```

Clé i18n : `backend.<plural>.delete_confirm` avec placeholder `{name}` ou `{title}`.

## Pourquoi

`confirm()` natif : moche, pas thématisable, pas accessible, pas testable, bloque le main thread. La modale permet de styliser le bouton destructif, nommer l'élément à supprimer, ajouter des avertissements.

## Comment l'appliquer

Pour un nouveau module avec CRUD :
1. Créer `use<Plural>Delete.js`.
2. Monter `deleting<Singular>` + `confirmDelete` dans `<Plural>App.vue`.
3. Bouton trash → `v-on:click="deleting<Singular> = entity"`.
4. Modale de confirmation à la fin du template.
5. Clé i18n `backend.<plural>.delete_confirm` avec `{name}`.

```bash
# Audit (core : src/, client : assets/)
grep -rn "confirm(" src/ assets/ --include="*.vue" --include="*.js" \
    | grep -v node_modules | grep -v ".test."
```
