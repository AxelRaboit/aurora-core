---
name: add-crud-list-ui
description: Scaffold the Vue UI for a backend CRUD list page following the Aurora pattern - toolbar with search + primary action, mobile card layout + desktop table, AppModal create/edit forms, AppModal delete confirmation. Use when the user asks to "create a list page", "add CRUD UI", "scaffold the Vue for an entity", or after `/add-entity` left the Vue stubbed out. Stops at backend (assumes Controller + ViewBuilder already exist with index + create + update + delete endpoints).
scope: core-and-client
---

# add-crud-list-ui

Generate a backend CRUD list page that follows the canonical Aurora
pattern (see `CompaniesApp.vue`, `TiersApp.vue`,
`PersonalFinanceWalletsApp.vue` for live references). Produces a 3-file
output: a thin SFC + two composables (`use<Plural>Create.js`,
`use<Plural>Edit.js`).

> ⛔ **Before generating, RELOAD these two memories**:
>
> 1. `convention_modal_and_confirmation.md` - `confirm()` natif est
>    INTERDIT. Always `AppModal` + `useDelete` shared composable for
>    deletes.
> 2. `convention_sfc_thin_presentation.md` - NO business logic inside
>    the `.vue`. Every CRUD flow goes into `composables/use<Plural><Action>.js`
>    co-located in the feature folder. The SFC only owns template +
>    UI-only refs (search input, modal toggles when not already managed
>    by the composable).
>
> **Server-side pagination is the DEFAULT.** Use `useListPage` + a backend
> `/list` JSON endpoint + `AppPagination` + `AppLoader`. Local filtering
> via a computed (which was a temporary fallback) is allowed only when
> the list is bounded by design (e.g. a small in-memory pick-list).

## File layout (mandatory)

```
assets/backend/<folder>/
├── <Plural>App.vue                   # template + thin glue
└── composables/
    ├── use<Plural>Create.js          # showCreate + createForm + createErrors + createLoading + openCreate + submitCreate
    └── use<Plural>Edit.js            # showEdit + editingItem + editForm + editErrors + editLoading + openEdit + submitEdit
```

Skip splitting ONLY when create/edit are literally one-field one-line
forms with no validation. Otherwise extract from the start.

## Required inputs

1. **Entity name** in PascalCase singular (`Wallet`, `Contact`) - used as
   `<Singular>`. Plural auto-derived; ask if irregular.
2. **Module path under `assets/backend/`** - e.g. `wallet/`, `companies/`,
   `category/`. Determines the Vue path used by `vue_component(…)`.
3. **Backend route names already wired in the Twig view-builder** - names
   of the props the Twig passes (e.g. `createWalletPath`, `updateWalletPath`,
   `deleteWalletPath`, and the list of items itself).
4. **Fields to render in the form** - name, type (text / decimal / select /
   date / boolean), validation (required, label). For each select: source
   of the options list.
5. **Searchable** (yes/no) - adds `AppSearchInput` in the toolbar with
   client-side filter on the local state. Default: yes.
6. **i18n base** - the `personal_finance.wallets` (or equivalent) namespace
   for keys.

## What gets generated

Single file `assets/backend/<folder>/<Plural>App.vue` containing :

### Imports (mandatory)

```js
import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";
import { Plus, Pencil, Trash2, Save, X } from "lucide-vue-next";
// Pick an entity-specific icon for the create modal: Wallet, Building2, …
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
```

Pick-and-choose extras :
- **Decimal field with calc** : `AppAmountInput.vue` (same folder as
  AppInput). Accepts `100+50` and evaluates on blur.
- **Date** : `AppDatePicker.vue` (`form/picker/`)
- **Tags / color** : `AppTagsInput.vue` / `AppColorField.vue`
- **Toggle** : `AppToggle.vue` / `AppCheckbox.vue`

### State

```js
const items = ref([...props.items]);          // local mutable copy
const searchInput = ref("");
const filteredItems = computed(() => {
    const q = searchInput.value.trim().toLowerCase();
    if (!q) return items.value;
    return items.value.filter((i) =>
        i.name.toLowerCase().includes(q),     // adapt: which field(s)?
    );
});

function emptyForm() { return { /* one entry per editable field */ }; }
const showCreate = ref(false);
const createForm = ref(emptyForm());
const createErrors = ref({});
const createLoading = ref(false);

const showEdit = ref(false);
const editingItem = ref(null);
const editForm = ref(emptyForm());
const editErrors = ref({});
const editLoading = ref(false);

const { pendingDelete, loading: deleteLoading, confirm: confirmDelete, submit: doDelete } = useDelete(
    props.deleteItemPath,
    (id) => { items.value = items.value.filter((i) => i.id !== id); },
    "<module>.<plural>.deleted",
);
```

### Submit helpers

```js
async function request(method, url, body = null) {
    const options = { method, headers: { Accept: "application/json" } };
    if (body !== null) {
        options.headers["Content-Type"] = "application/json";
        options.body = JSON.stringify(body);
    }
    const response = await fetch(url, options);
    const payload = await response.json().catch(() => ({}));
    return { ok: response.ok && payload.success !== false, payload };
}

function openCreate() { createForm.value = emptyForm(); createErrors.value = {}; showCreate.value = true; }
async function submitCreate() {
    createLoading.value = true; createErrors.value = {};
    try {
        const res = await request(HttpMethod.Post, props.createItemPath, createForm.value);
        if (!res.ok) { createErrors.value = res.payload?.errors ?? {}; return; }
        items.value = [...items.value, res.payload.item];
        showCreate.value = false;
    } finally { createLoading.value = false; }
}

function openEdit(item) { editingItem.value = item; editForm.value = { /* copy fields */ }; editErrors.value = {}; showEdit.value = true; }
async function submitEdit() {
    if (!editingItem.value) return;
    editLoading.value = true; editErrors.value = {};
    try {
        const url = buildPath(props.updateItemPath, { id: editingItem.value.id });
        const res = await request(HttpMethod.Post, url, editForm.value);
        if (!res.ok) { editErrors.value = res.payload?.errors ?? {}; return; }
        const idx = items.value.findIndex((i) => i.id === editingItem.value.id);
        if (idx !== -1) items.value[idx] = res.payload.item;
        showEdit.value = false;
    } finally { editLoading.value = false; }
}
```

### Template skeleton

```vue
<template>
    <div class="space-y-4">
        <AppListToolbar>
            <AppSearchInput v-model="searchInput" :placeholder="t('<base>.search_placeholder')" />
            <template #actions>
                <AppButton variant="primary" size="md" class="w-full sm:w-auto" v-on:click="openCreate">
                    <Plus class="w-4 h-4" :stroke-width="2" />
                    {{ t("<base>.add") }}
                </AppButton>
            </template>
        </AppListToolbar>

        <div class="space-y-4">
            <!-- MOBILE : cards -->
            <div class="sm:hidden space-y-3">
                <div v-for="i in filteredItems" :key="i.id" class="bg-surface border border-line rounded-lg p-4 space-y-3">
                    <!-- per-field layout -->
                    <div class="flex items-center justify-end gap-0.5 pt-2 border-t border-line">
                        <AppIconButton color="accent" :title="t('shared.common.edit')" v-on:click="openEdit(i)"><Pencil class="w-4 h-4" :stroke-width="2" /></AppIconButton>
                        <AppIconButton color="rose" :title="t('shared.common.delete')" v-on:click="confirmDelete(i)"><Trash2 class="w-4 h-4" :stroke-width="2" /></AppIconButton>
                    </div>
                </div>
            </div>

            <!-- DESKTOP : table -->
            <div class="hidden sm:block bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-surface-2/50 border-b border-line/40">
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">…</th>
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t("shared.common.actions") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/40">
                        <tr v-for="i in filteredItems" :key="i.id" class="group hover:bg-surface-2/40 transition-colors">
                            <td class="px-6 py-3"><span class="font-medium text-primary">{{ i.name }}</span></td>
                            <td class="px-6 py-3">
                                <div class="flex items-center justify-end gap-0.5">
                                    <AppIconButton color="accent" :title="t('shared.common.edit')" v-on:click="openEdit(i)"><Pencil class="w-4 h-4" :stroke-width="2" /></AppIconButton>
                                    <AppIconButton color="rose" :title="t('shared.common.delete')" v-on:click="confirmDelete(i)"><Trash2 class="w-4 h-4" :stroke-width="2" /></AppIconButton>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!filteredItems.length">
                            <td :colspan="N" class="px-6 py-8 text-center text-sm text-muted">
                                {{ items.length ? t("<base>.no_match") : t("<base>.empty") }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- CREATE modal -->
        <AppModal :show="showCreate" :title="t('<base>.create_form_title')" :icon="EntityIcon" :closeable="false" v-on:close="showCreate = false">
            <form class="space-y-4" v-on:submit.prevent="submitCreate">
                <AppInput v-model="createForm.name" :label="…" :error="createErrors.name" required />
                <!-- other fields -->
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" type="button" v-on:click="showCreate = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" type="submit" :loading="createLoading" v-on:click="submitCreate"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- EDIT modal - same structure, title via t('<base>.edit', { name: editingItem?.name ?? '' }) -->

        <!-- DELETE modal -->
        <AppModal :show="!!pendingDelete" max-width="sm" :closeable="false" :title="t('shared.common.delete')" :icon="Trash2" v-on:close="pendingDelete = null">
            <p class="text-sm text-primary">{{ t("<base>.delete_confirm", { name: pendingDelete?.name ?? "" }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
```

## i18n keys to declare (FR + EN)

Under `<base>` namespace (e.g. `personal_finance.wallets`):

| Key | Purpose | Example FR |
|---|---|---|
| `title` | h1 page title | "Mes portefeuilles" |
| `empty` | empty state (no items) | "Aucun portefeuille…" |
| `no_match` | search no results | "Aucun portefeuille ne correspond…" |
| `search_placeholder` | toolbar search | "Rechercher un portefeuille…" |
| `add` | toolbar create button | "Nouveau portefeuille" |
| `create_form_title` | create modal title | "Créer un portefeuille" |
| `edit` | edit modal title (with `{name}`) | "Modifier « {name} »" |
| `delete_confirm` | delete modal body (with `{name}`) | "Supprimer le portefeuille « {name} » ?" |
| `deleted` | toast on successful delete | "Portefeuille supprimé." |
| `fields.<x>` | field labels | "Nom", "Balance initiale" |
| `placeholders.<x>` | field placeholders | "ex. Compte courant" |
| `errors.<x>_required` etc | server validation labels | "Le nom est requis." |

Don't add `actions.delete` / `actions.create` under `<base>` - use
`shared.common.{delete,cancel,save,edit,actions}` (already present).

## Choice cheatsheet - which input component for which field

| Field | Component |
|---|---|
| Text (short) | `AppInput` |
| Text (long) | `AppTextarea` |
| Email | `AppInput` with `type="email"` |
| Password | `AppInput` with `:toggleable="true"` |
| Decimal amount (with calc) | `AppAmountInput` - accepts `100+50` |
| Plain number | `AppInput` with `inputmode="numeric"` |
| Single-select | `AppMultiselect` with `:multiple="false"` `:allow-empty="false"` |
| Multi-select / tags-like | `AppMultiselect` with `:multiple="true"` |
| Free-form tags | `AppTagsInput` |
| Date | `AppDatePicker` |
| Color | `AppColorField` |
| Boolean toggle | `AppToggle` |
| Boolean checkbox (forms) | `AppCheckbox` |
| Search (toolbar) | `AppSearchInput` |

## Variants for non-default cases

- **Heavy form (>6 fields, multi-tab)** : extract `use<Plural>Create.js` +
  `use<Plural>Edit.js` composables (cf. `CompaniesApp` for the canonical
  pattern). Otherwise inline is fine.
- (~~Server-side pagination~~ - promoted to default, see "What gets generated".)
- **Show/Detail page link** : add `<AppIconButton color="sky"
  :href="buildPath(showPath, { id: i.id })"><Eye/></AppIconButton>` before
  edit/delete (eye icon).
- **Per-row permission check** : wrap each action with `v-if="can('…')"`
  from `usePrivileges`.

## Anti-patterns to avoid

- ❌ `confirm()` / `alert()` / `prompt()` - see
  `convention_modal_and_confirmation.md`
- ❌ Inline `<button>` for actions - always `AppButton` (forms) or
  `AppIconButton` (table actions)
- ❌ Raw HTML `<input>` - always `AppInput` / `AppAmountInput` / etc
- ❌ Raw `<select>` - always `AppMultiselect`
- ❌ `vue_component('module-name/...')` with kebab-case - must be
  `vue_component('modulename/...')` (lowercase compact, cf.
  `convention_naming.md` §"Cas particulier")

## Post-generation

1. Run `pnpm dev` (or check Vite is up) - Vite hot-reloads the new Vue.
2. Verify the Twig template uses the correct `vue_component('<module-lowercase>/backend/<folder>/<Plural>App')` reference.
3. Test the golden path : create → list updates → edit → list updates → delete (with modal) → list updates.
4. Translations should auto-dump via `predev` hook ; otherwise `php bin/console app:translations:dump-js`.
5. Final check : `make ft` should still be green.
