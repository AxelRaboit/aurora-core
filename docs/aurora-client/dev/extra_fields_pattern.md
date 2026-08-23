# Pattern `extraFields` + slots scoped

> À lire quand vous voulez **ajouter un ou plusieurs champs personnalisés** à
> une page admin Aurora (un nouveau champ `code` sur Agency, une `priority` sur
> Project, etc.) sans forker le composant `<Plural>App.vue`. Ce doc décrit
> uniquement la couche Vue 5 du pattern Sylius-style d'Aurora ; pour le
> remplacement complet d'un composant, voir [extend_module.md](../extending/extend_module.md). Pour
> les conventions générales d'assets Vue, voir [assets_vue.md](assets_vue.md).

---

## 1. Le contrat en deux lignes

Tout composant `<Plural>App.vue` d'Aurora qui suit la convention
d'extensibilité expose **deux choses** au client :

1. Une prop `extraFields: Object` (défaut `{}`) - déclare quels champs sont
   ajoutés, leur valeur initiale et comment les lire depuis une entité existante.
2. Trois slots scoped - `extra-headers`, `extra-cells`, `extra-form-fields` -
   pour rendre l'UI correspondante (colonne dans la table, cellule par ligne,
   input dans la modale create/edit).

Le composable `useXxxForm` (ou `useXxxEdit`) accepte la même `extraFields` et
fusionne automatiquement les clés dans son `editForm`. Le payload envoyé au
backend est `{ ...editForm }` - donc tout ce que vous déclarez via
`extraFields` est sérialisé sans plomberie supplémentaire.

---

## 2. Forme exacte de `extraFields`

```js
{
    <fieldName>: {
        default: <valeur initiale pour la création>,
        fromEntity: (entity) => <valeur lue depuis l'entité en édition>,
    },
    // …
}
```

Chaque entrée :

| Clé | Type | Rôle |
|---|---|---|
| `default` | `*` | Valeur initiale dans `editForm` à l'ouverture en mode create, et au reset. |
| `fromEntity` | `(entity) => *` | Optionnel. Lit la valeur sur une entité existante pour pré-remplir `editForm` en mode edit. Si absent, `default` est utilisé. |

> Si l'API backend renvoie déjà le champ via le `Serializer` étendu côté
> client (cf. la couche 4 de la convention), `fromEntity: (entity) => entity.code`
> suffit. Sinon, le mapping peut être plus riche : `fromEntity: (e) => e.meta?.code ?? ""`.

Le composable `useXxxForm` boucle sur `Object.keys(extraFields)` pour :

- ajouter chaque clé dans `editForm` à la construction (`default`),
- réinjecter chaque clé via `fromEntity(entity)` à l'ouverture en édition,
- inclure chaque clé dans le payload `{ ...editForm }` au submit.

Vous **ne devez jamais** muter `editForm` manuellement avec un champ qui
n'est pas déclaré via `extraFields` - il ne survivrait pas au reset/edit.

---

## 3. Référence de code dans aurora-core

Le composant exemplaire est
`src/Module/Ecommerce/assets/backend/listing_categories/ListingCategoriesApp.vue`
et son composable
`src/Module/Ecommerce/assets/backend/listing_categories/composables/useListingCategoriesForm.js`.

### Côté composant (extrait fidèle)

```vue
<script setup>
const props = defineProps({
    categories: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    listPath: { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    reorderPath: { type: String, required: true },
    extraFields: { type: Object, default: () => ({}) },
});

const {
    showCreate, showEdit, editingCategory,
    editForm, formImage,
    createErrors, createLoading, editErrors, editLoading,
    openCreate, openEdit, submitCreate, submitEdit, autoSlug,
} = useListingCategoriesForm({
    createPath: props.createPath,
    updatePath: props.updatePath,
    locales: props.locales,
    reset: reload,
    extraFields: props.extraFields,
});
</script>

<template>
    <AppModal :show="showCreate || showEdit" v-on:close="…">
        <form v-on:submit.prevent="showEdit ? submitEdit() : submitCreate()">
            <!-- Champs Aurora « natifs » -->
            <AppInput v-model="editForm.translations[activeTab].name" … />

            <!-- Point d'extension client -->
            <slot
                name="extra-form-fields"
                :edit-form="editForm"
                :errors="showEdit ? editErrors : createErrors"
            />
        </form>
    </AppModal>
</template>
```

### Côté composable (extrait fidèle)

```js
function buildEmptyForm(locales, extraFields = {}) {
    const translations = {};
    for (const locale of locales) translations[locale.code] = emptyTranslation();
    const extras = {};
    for (const key of Object.keys(extraFields)) {
        extras[key] = extraFields[key].default;
    }
    return { parentId: null, position: 0, /* … */, translations, ...extras };
}

function loadFromCategory(category) {
    resetForm();
    // … champs Aurora …
    for (const key of Object.keys(extraFields)) {
        const fromEntity = extraFields[key].fromEntity;
        editForm[key] = fromEntity ? fromEntity(category) : extraFields[key].default;
    }
}

// Submit : un simple spread suffit, les extras passent automatiquement
function buildBody() {
    return { ...editForm, /* normalizations */ };
}
```

Notez le pattern à reproduire dans vos propres composants Aurora si vous en
écrivez un : **boucler sur `Object.keys(extraFields)` à 3 endroits** : init,
load-from-entity, et reset. Le submit n'a rien à faire de spécial - c'est le
spread qui transporte les valeurs.

---

## 4. Walkthrough - ajouter `code: string` sur `Agency`

Pré-requis côté PHP (cf. doc `extending_agency_pilot.md` côté core) : entité,
DTO, Manager et Serializer ont été étendus pour persister/sérialiser `code`.
On part du principe que `entity.code` revient bien dans le JSON de la liste.

### 4.1 Variante simple - pas d'override de la Vue Aurora

Si vous n'avez pas besoin de modifier le rendu, **vous n'avez rien à overrider** :
il suffit de passer `extraFields` et de remplir les slots **dans le Twig** qui
invoque le composant Aurora.

> ⚠ En pratique, les slots Vue ne se passent pas directement depuis Twig - il
> faut un composant wrapper Vue côté client. Sautez directement à 4.2.

### 4.2 Variante courante - wrapper Vue côté client

1. Créez `assets/client/Module/Crm/admin/AgenciesAppExtended.vue` (ou tout autre
   nom - le path déclenche l'identifiant `vue_component`).

```vue
<script setup>
import AgenciesApp from "@core/backend/agencies/AgenciesApp.vue";
import AppInput from "@shared/components/form/AppInput.vue";

defineProps({
    agencies:   { type: Array, default: () => [] },
    listPath:   { type: String, required: true },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

const extraFields = {
    code: {
        default: "",
        fromEntity: (agency) => agency.code ?? "",
    },
};
</script>

<template>
    <AgenciesApp
        :agencies="agencies"
        :list-path="listPath"
        :create-path="createPath"
        :update-path="updatePath"
        :delete-path="deletePath"
        :extra-fields="extraFields"
    >
        <!-- Colonne supplémentaire dans la table -->
        <template #extra-headers>
            <th class="px-3 py-2 text-left text-xs uppercase text-secondary">
                {{ $t('client.agencies.code') }}
            </th>
        </template>

        <!-- Cellule supplémentaire par ligne (scope : `agency`) -->
        <template #extra-cells="{ agency }">
            <td class="px-3 py-2 text-sm text-primary">{{ agency.code }}</td>
        </template>

        <!-- Champ supplémentaire dans la modale create/edit
             (scope : `editForm` + `errors`) -->
        <template #extra-form-fields="{ editForm, errors }">
            <AppInput
                v-model="editForm.code"
                :label="$t('client.agencies.code')"
                :error="errors.code"
            />
        </template>
    </AgenciesApp>
</template>
```

2. Dans le Twig de la page admin, pointez vers votre composant client à la
   place du composant Aurora :

```twig
{# templates/Core/backend/agencies/index.html.twig (override client) #}
{% extends '@CoreBackend/layout.html.twig' %}

{% block body %}
    {{ vue_component('crm/admin/AgenciesAppExtended', {
        agencies: agencies,
        listPath: path('core_backend_agency_list_json'),
        createPath: path('core_backend_agency_create'),
        updatePath: path('core_backend_agency_update', {id: '__id__'}),
        deletePath: path('core_backend_agency_delete', {id: '__id__'}),
    }) }}
{% endblock %}
```

> Le chemin Twig override est résolu en priorité grâce au prepend automatique
> du bundle (cf. `assets_vue.md`). Pas besoin de toucher à aurora-core.

### 4.3 Vérifier en 30 s

1. `make dev` (Vite watch).
2. Recharger la page admin agences.
3. Créer une agence : le champ `code` apparaît dans la modale, il part bien
   dans le POST (network tab → JSON body avec `code`).
4. La colonne `code` s'affiche dans la table sans avoir touché à aurora-core.
5. Éditer une agence : `code` est pré-rempli via `fromEntity`.

---

## 5. Conventions à respecter dans le wrapper

| Règle | Pourquoi |
|---|---|
| `editForm.code` doit rester **un primitif** (string/number/boolean/array de primitifs). | Le payload est `{ ...editForm }` - un `Date`, un `ref()` imbriqué ou un computed casse la sérialisation. |
| Le slot `extra-form-fields` reçoit `editForm` et `errors` - utilisez le scope, ne réimportez pas votre propre form. | Le composable owner gère le reset/load/clearErrors. Si vous créez un `reactive()` parallèle, vous perdez ces synchronisations. |
| Les `errors` exposés sont déjà traduits côté composable (via `translateServerErrors`). Affichez-les bruts : `:error="errors.code"`. | Pas besoin de `t(errors.code)` côté client. |
| Une seule clé `extra-form-fields` par champ supplémentaire - utilisez `<template>` group si besoin de plusieurs inputs côte-à-côte. | Le slot n'a pas de wrapper imposé : vous gérez la mise en page. |
| `<template #extra-cells="{ agency }">` - toujours destructurer le scope. Le nom de la variable suit l'entité (`agency`, `project`, `post`, etc.). | C'est ce qu'expose le composant Aurora : voir la définition dans son template. |

---

## 6. Quand `extraFields` ne suffit pas

Trois cas où il faut **dépasser** ce pattern :

### 6.1 Le form de création et d'édition divergent fortement

Pattern de référence : `Theme` et `User` dans aurora-core, qui exposent
**deux composables séparés** `useXxxCreate` + `useXxxEdit`. Le composant
Aurora expose alors deux jeux de slots : `extra-create-form-fields` et
`extra-form-fields`. Côté client, c'est exactement le même contrat -
juste deux templates au lieu d'un. Voir la sous-section 4.bis.1bis de
[`entity_extensibility_convention.md`](../../aurora-core/dev/entity_extensibility_convention.md)
(côté core).

### 6.2 Le champ exige un panel entier (pas un input simple)

Ex : ajouter une section "Intégrations" avec 5 champs liés. Le slot
`extra-form-fields` reste valide - vous y placez votre propre composant
panel :

```vue
<template #extra-form-fields="{ editForm, errors }">
    <IntegrationsPanel
        v-model:integration-id="editForm.integrationId"
        v-model:integration-token="editForm.integrationToken"
        :errors="errors"
    />
</template>
```

Toutes les clés (`integrationId`, `integrationToken`, …) doivent être
déclarées via `extraFields` pour passer dans le payload.

### 6.3 Le form a divergé au-delà de ce qu'un slot peut accueillir

Si vous changez la **structure** du form (sections, layout, ordre), votre
seule option propre est l'**override complet** du composant
`<Plural>App.vue` - voir [extend_module.md](../extending/extend_module.md). Dans ce cas, plus
besoin d'`extraFields` : vous gérez `editForm` vous-même.

> Avant de partir sur un override complet, demandez-vous si le contrat
> couche 5 d'Aurora peut être étendu côté core (PR upstream). Un override
> total est un coût de maintenance à chaque `composer update axelraboit/aurora`.

---

## 7. Récap one-shot

```text
Côté client :
  1. extraFields = { code: { default: "", fromEntity: e => e.code } }
  2. <AgenciesApp :extra-fields="extraFields">
       <template #extra-headers>…</template>
       <template #extra-cells="{ agency }">…</template>
       <template #extra-form-fields="{ editForm, errors }">
         <AppInput v-model="editForm.code" :error="errors.code" />
       </template>
     </AgenciesApp>

Côté Aurora (déjà fait) :
  - Composable spread les extraFields dans editForm
  - Submit envoie { ...editForm }
  - Le DTO + Manager + Serializer côté PHP gèrent la persistance
```

C'est tout. Aucune autre plomberie nécessaire.
