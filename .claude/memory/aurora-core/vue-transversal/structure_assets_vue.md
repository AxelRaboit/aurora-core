# Assets Vue / Composables — convention de structure

## Règle

```
src/Core/assets/                          ← cross-cutting JS/Vue (depuis 0.5)
├── backend/
│   ├── <plural>/                           ← un dossier par entité Core (au pluriel)
│   │   ├── <Plural>App.vue                  ← composant principal monté par Stimulus
│   │   ├── <Detail>App.vue                  ← optional, page détail
│   │   └── composables/
│   │       ├── use<Plural>Form.js           ← create+edit unifié (option extraFields)
│   │       ├── use<Plural>Delete.js
│   │       ├── use<Plural>List.js
│   │       └── … (autres composables spécifiques)
│   ├── sidemenu/                           ← composants sidemenu admin
│   └── notifications/                      ← composants notifications admin
├── frontend/                               ← composants frontend public
├── utils/                                  ← helpers JS partagés Core (enums…)
├── shared/                                 ← composants & composables réutilisables
│   ├── components/                          ← AppButton, AppInput, AppModal, etc.
│   │   ├── action/
│   │   ├── feedback/
│   │   ├── form/
│   │   ├── nav/
│   │   └── overlay/
│   ├── composables/
│   │   ├── http/                            ← useRequest, etc.
│   │   ├── form/                           ← useForm
│   │   ├── format/                         ← useDateFormat
│   │   └── list/                           ← useListPage, useUrlSearchSync
│   └── utils/                              ← buildPath, httpMethod, validation, …
├── locales/                                ← traductions générées + sources
├── stimulus/                               ← controllers Stimulus (renommé)
├── stimulus.json                           ← config Symfony StimulusBridge
├── css/                                    ← CSS partagé (base/, shared/, core/)
└── {app,flash,theme,guest,i18n,stimulus_bootstrap}.js  ← entry points

src/Module/<Module>/assets/                ← JS/Vue spécifique au module
├── backend/                                ← exclusif contexte admin
│   ├── <Plural>App.vue                     ← composant top-level
│   ├── components/                         ← sous-composants backend-only
│   ├── composables/                        ← logique métier backend
│   └── utils/                              ← helpers backend-only
├── frontend/                               ← exclusif contexte public (si applicable)
│   ├── composables/
│   └── …
└── shared/                                 ← partagé entre backend ET frontend
    ├── components/                         ← composants réutilisés des deux côtés
    └── utils/                              ← enums, formatters, helpers cross-context
```

### Compartimentage en sous-dossiers feature

Dès qu'un dossier `backend/` (ou `Core/backend/<section>/`) contient plusieurs features distinctes (≥ 2 `*App.vue` ou ≥ 8 fichiers), chaque feature obtient son propre sous-dossier :

```
<module>/backend/<feature>/
  <Feature>App.vue
  composables/
    useXxx.js
  components/        ← si plusieurs composants internes
```

Règles :
- Nom en `kebab-case` (ex: `document-categories/`, `mount-points/`)
- Même sous-dossier pour la liste ET le détail (ex: `invoices/InvoicesApp.vue` + `InvoiceShowApp.vue`)
- Le `vue_component('module/backend/<feature>/<Name>App', ...)` dans le Twig reflète le chemin
- Imports cross-feature : chemins relatifs remontants (`../events/composables/useEventForm.js`)
- `shared/`, `components/`, `utils/`, `constants/` à la **racine** du module (cross-cutting)
- Modules single-feature ≤ 6 fichiers : pas de sous-dossier (ex: `PasswordGenerator/backend/`)

Voir [`convention_assets_subfolder_layout.md`](convention_assets_subfolder_layout.md) pour la table complète des modules.

### Règle de placement dans un module

Un fichier va dans `backend/`, `frontend/` ou `shared/` selon **qui l'importe** :

| Importé uniquement par `backend/` | Importé uniquement par `frontend/` | Importé des deux côtés |
|---|---|---|
| → `backend/` | → `frontend/` | → `shared/` |

**Modules sans frontend** (pas de contexte public) : tout va dans `backend/`, jamais à la racine du module.

Exemples concrets :
- `Billing/` (pas de frontend) → `backend/components/`, `backend/utils/`
- `Crm/` (pas de frontend) → `backend/utils/`
- `Editorial/` (backend + frontend) → `utils/editorjs/` utilisé des deux côtés → `shared/utils/editorjs/`
- `Ecommerce/` (backend + frontend) → `utils/enums/`, `utils/formatMoney.js` utilisés des deux côtés → `shared/utils/`
- `Vault/` (backend-only) → tout sous `backend/` ✅

**Anti-pattern** : dossier `components/` ou `utils/` directement à la racine d'un module (même niveau que `backend/`). C'est un signe que le placement n'a pas été réfléchi.

## Composants Vue

### Naming
- **PascalCase** : `AgenciesApp.vue`, `PostEditor.vue`, `ImageCropperModal.vue`.
- **App suffixe** : composant top-level monté via Stimulus
  (`AgenciesApp.vue`, `DocumentsApp.vue`, `UsersApp.vue`).
- **Modal/Overlay suffixe** : composant secondaire ouvert par l'App
  (`ImageCropperModal.vue`, `RevisionsOverlay.vue`).
- **Panel suffixe** : section d'un editor (`PostSeoPanel.vue`,
  `PostFeaturedImagePanel.vue`).
- **Tab suffixe** : onglet dans un AppTabs (`PermissionsTab.vue`).

### Convention extension (rappel)
Chaque `<Plural>App.vue` (composant top-level d'backend CRUD) expose :
- **Prop `extraFields`** (`{ type: Object, default: () => ({}) }`)
- **3 slots scoped** : `extra-headers`, `extra-cells`, `extra-form-fields`

Cf [`convention_extensibility.md`](convention_extensibility.md) couche 5
et [`client/pattern_extend_vue.md`](client/pattern_extend_vue.md).

## Composables JS

### Localisation
`src/Module/<Module>/assets/<scope>/<plural>/composables/use<Plural><Action>.js`
(ou `src/Core/assets/<scope>/<plural>/composables/...` pour les Core).

### Naming
- `use<Plural>Form.js` : composable unifié create+edit (le plus courant).
- `use<Plural>Delete.js` : confirmation + delete.
- `use<Plural>List.js` : liste réactive (souvent juste un wrapper).
- `use<Plural>Filters.js` : filtres de recherche.
- `use<Plural>Kanban.js`, `use<Plural>Reorder.js` : actions spécifiques.

### Pattern composable Form unifié

```js
// useAgenciesForm.js
import { reactive } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/useRequest.js";

/**
 * @typedef {Object} ExtraField
 * @property {*} default - Initial/reset value for this field.
 * @property {(agency: object) => *} fromEntity - Reads field value from existing agency.
 */

export function useAgenciesForm(agencyList, createPath, updatePath, options = {}) {
    const { t } = useI18n();
    const { request } = useRequest();
    const extraFields = options.extraFields ?? {};

    const editModal = reactive({ open: false, agency: null, errors: {}, saving: false });
    const editForm = reactive({
        name: "",
        ...Object.fromEntries(
            Object.entries(extraFields).map(([key, def]) => [key, def.default]),
        ),
    });

    function openCreate() { /* reset form + open modal */ }
    function openEdit(agency) { /* hydrate form + open modal */ }
    async function submitEdit() { /* POST to create/update + handle response */ }

    return { editModal, editForm, openCreate, openEdit, submitEdit };
}
```

### Variantes de composable
- **Composable form unifié** (Agency, Service, Theme[create-only],
  Company, Contact, Deal, Order admin, Form, Comment, Document,
  DocumentCategory, Listing, Product, Gallery, Menu, Media, …) — la
  norme.
- **Composable séparé** create + edit (User invite/edit, Theme
  create/edit) : 2 composables distincts. Cf
  [`decision_variant_user_style.md`](decision_variant_user_style.md).
- **Editor full-page** (Post) : pas de composable form séparé — la logique
  est dans le composant `PostEditor.vue` directement (trop de complexité
  pour un composable).

## Stimulus controllers

Le projet utilise Stimulus comme bridge Twig → Vue. Controllers dans
`src/Core/assets/stimulus/` (un controller par usage : `vue-mount`,
`form-validation`, `notification-bell`, etc.).

Pattern principal : `data-controller="vue-mount" data-vue-mount-component-value="…"`
pour mount un composant Vue depuis Twig.

## Conventions de code Vue

### Reactive vs Ref
- `ref()` pour les valeurs scalaires ou tableaux entiers.
- `reactive()` pour les objets de form (`editForm`, `editModal`).
- ❌ **Pas de ref imbriqué dans reactive** : sérialisation imprévisible.
  Utiliser des ref/reactive séparés.

### Imports avec alias
- `@/shared/...` pour les helpers shared.
- `@core/backend/...` pour les composants Core admin.
- `@<module>/backend/...` pour les composants module admin.

Les alias sont configurés dans `vite.config.js` côté Core.

### Style

- `<script setup>` Composition API (Vue 3).
- `defineProps({ ... })` pour les props.
- Pas de Options API (pas de `data() { return ... }`).

## Build

```bash
npm run dev    # Vite dev server
npm run build  # Production build
```

Côté client, le build est lancé via `pnpm` depuis le dossier aurora-core
en mode `AURORA_ENV` qui scanne aussi le projet client — depuis 0.5 sa
racine (`src/Module/<X>/assets/`, `src/Overrides/`), plus `assets/client/`
qui n'existe plus.

Cf `aurora-core/Makefile` (target `build` / `dev`) pour les détails.

## Anti-patterns

- ❌ Composant top-level sans `extraFields` prop ni slots scoped (impossible
  à étendre côté client).
- ❌ Composables qui font de l'HTTP direct via `fetch()` au lieu d'utiliser
  `useRequest` (rate de gérer toast d'erreur, loading state, etc.).
- ❌ Logique métier dans le composant (calculs complexes, transitions de
  statut). Mettre dans un composable dédié.
- ❌ Composant naming inconsistant : `AgenciesApp` (plural) vs
  `PostEditor` (singular). Pour les backend CRUD top-level → toujours
  `<Plural>App`.
