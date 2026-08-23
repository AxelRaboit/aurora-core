# Pattern : étendre la Vue admin

## Règle

Pour ajouter un champ dans le formulaire admin Vue d'une entité Aurora,
**deux mécanismes scoped** :

1. **Prop `extraFields`** : déclare les champs custom + leurs `default` /
   `fromEntity` (callback d'hydratation depuis une entité existante).
2. **Slots scoped** : `extra-headers` (colonne table), `extra-cells` (cell
   table), `extra-form-fields` (input dans la modal/page edit).

## Pourquoi

Le composant aurora-core (`<Plural>App.vue`) reste **non-modifié**. Le
client crée un wrapper qui le consomme et passe `extraFields` + utilise
les slots. Mise à jour d'aurora-core = pas de conflit.

## Comment l'appliquer

### 1. Wrapper Vue côté client

Le wrapper est **co-localisé avec l'extension PHP** sous
`src/Module/<AuroraModule>/<Feature>/assets/`. Le glob auto-flatten les
feature folders, donc la clé exposée matche exactement celle d'Aurora →
shadow direct, pas de Twig override à écrire. Voir
[[convention_overrides_vs_modules]] pour la règle des deux mirrors.

```vue
<!-- src/Module/Platform/Agency/assets/backend/agencies/AgenciesApp.vue -->
<script setup>
import AuroraAgenciesApp from '@platform/backend/agencies/AgenciesApp.vue';
import AppInput from '@/shared/components/form/AppInput.vue';

const extraFields = {
    code: {
        default: '',
        fromEntity: (agency) => agency.code ?? '',
    },
};
</script>

<template>
    <AuroraAgenciesApp v-bind="$attrs" :extra-fields="extraFields">
        <template #extra-headers>
            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">
                Code
            </th>
        </template>

        <template #extra-cells="{ agency }">
            <td class="px-6 py-3 font-mono text-sm">
                {{ agency.code ?? '-' }}
            </td>
        </template>

        <template #extra-form-fields="{ form, errors }">
            <AppInput
                v-model="form.code"
                label="Code"
                :error="errors.code ?? ''"
            />
        </template>
    </AuroraAgenciesApp>
</template>
```

### 2. Aucun wiring Twig nécessaire

Le glob `@client/src/Module/**/assets/**/*.vue` expose ton wrapper sous
la **même clé** que le composant Aurora original
(`platform/backend/agencies/AgenciesApp`). Comme `clientModules` est spread
APRÈS `auroraModules` dans `vueContext` (cf. `src/Core/assets/app.js`),
ton fichier wins. Le template Aurora
`@Platform/backend/agencies/index.html.twig` qui appelle
`vue_component('platform/backend/agencies/AgenciesApp', ...)` résout
directement ton wrapper - zéro override Twig à écrire.

### 3. Hydratation côté backend

Le `code` du `editForm` est envoyé dans le POST. Côté backend, le DTO
étendu (cf [pattern_extend_dto.md](pattern_extend_dto.md)) le récupère, et
le Manager étendu (cf [pattern_extend_manager.md](pattern_extend_manager.md))
l'hydrate dans l'entité.

## API `extraFields`

```js
const extraFields = {
    <fieldName>: {
        default: <valeur initiale au openCreate>,
        fromEntity: (entity) => <valeur lors d'openEdit(entity)>,
    },
    // …
};
```

- `default` : valeur quand on ouvre le formulaire create (newAgency).
- `fromEntity` : callback pour hydrater le formulaire edit depuis une
  entité existante. **Nom standardisé** : `fromEntity` (pas `fromAgency`,
  `fromDeal`, etc.).

Les composables `useXxxForm.js` aurora-core consomment cette config :
- `openCreate()` → `editForm[fieldName] = def.default`
- `openEdit(entity)` → `editForm[fieldName] = def.fromEntity(entity)`
- `submit()` → POST contient les champs custom dans le payload

## Slots scoped

| Slot | Scope | Usage |
|---|---|---|
| `extra-headers` | (rien) | Colonne `<th>` dans le table header |
| `extra-cells` | `{ agency }` (ou nom de l'entité) | Cell `<td>` dans le table body |
| `extra-form-fields` | `{ form, errors, agency }` | Input(s) dans la modal/page edit |

Pour les variantes :
- **Composables séparés** (User, Theme) : 2 slots distincts
  `extra-create-form-fields` + `extra-form-fields` (cf
  [decision_variant_user_style.md](../decision_variant_user_style.md)).
- **Editor full-page** (Post) : `extra-form-fields` placé sémantiquement
  près d'un panel proche par fonction (ex: après le panel "custom fields"
  PostType).

## Pièges

### 1. Reactive vs ref

```js
// ❌ MAUVAIS - ref dans reactive
const editForm = reactive({
    name: "",
    selectedTags: ref([]),  // ref imbriqué dans reactive
});

// ✅ BON
const editForm = reactive({ name: "" });
const selectedTags = ref([]);  // séparé
```

### 2. `extraFields` pas réactif

`extraFields` est destructuré au mount. Pas la peine de le faire
`reactive` côté client - il est lu une fois pour initialiser la config du
composable.
