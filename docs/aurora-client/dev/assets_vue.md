# Assets Vue - Composants côté client

## Structure

```
assets/client/
├── Module/
│   └── <ModuleName>/
│       ├── admin/
│       │   └── <Feature>App.vue      # composant admin du module
│       └── frontend/
│           └── <Feature>App.vue      # composant frontend public
├── Overrides/
│   └── backend/
│       └── <feature>/
│           └── <Feature>App.vue      # remplace un composant Aurora
└── locales/
    ├── fr.js                          # traductions Vue-only (FR)
    └── en.js                          # traductions Vue-only (EN)
```

---

## Conventions de nommage

Les composants sont enregistrés automatiquement par Aurora selon leur chemin :

| Fichier | Identifiant vue_component |
|---|---|
| `src/Module/Tracking/assets/admin/ProjectsApp.vue` | `tracking/admin/ProjectsApp` |
| `src/Module/Platform/Agency/assets/backend/agencies/AgenciesApp.vue` | `platform/backend/agencies/AgenciesApp` *(co-localisé avec l'extension PHP - shadow direct via clientModules)* |

Dans Twig :

```twig
{{ vue_component('tracking/admin/ProjectsApp') }}
{{ vue_component('platform/backend/agencies/AgenciesApp') }}
```

> Convention complète des 3 buckets sous `src/` :
> [`convention_overrides_vs_modules.md`](../../../.claude/memory/aurora-client/convention_overrides_vs_modules.md).

---

## Aliases Vite disponibles

Source de vérité : `aliases.js` à la racine du repo aurora-core (consommé
par Vite et Vitest). Côté client, `make sync-jsconfig` régénère
`jsconfig.json` à partir de cette source.

| Alias | Chemin (côté client : préfixer par `vendor/axelraboit/aurora/`) |
|---|---|
| `@` | `src/Core/assets/` |
| `@core` | `src/Core/assets/` |
| `@shared` | `src/Core/assets/shared/` |
| `@editorial` | `src/Module/Editorial/assets/` |
| `@crm` | `src/Module/Crm/assets/` |
| `@erp` | `src/Module/Erp/assets/` |
| `@ecommerce` | `src/Module/Ecommerce/assets/` |
| `@photo` | `src/Module/Photo/assets/` |
| `@billing` | `src/Module/Billing/assets/` |
| `@ged` | `src/Module/Ged/assets/` |
| `@hr` | `src/Module/Hr/assets/` |
| `@planning` | `src/Module/Planning/assets/` |
| `@project` | `src/Module/Project/assets/` |
| `@notes` | `src/Module/Notes/assets/` |
| `@assistant` | `src/Module/Assistant/assets/` |
| `@vault` | `src/Module/Vault/assets/` |
| `@password-generator` | `src/Module/PasswordGenerator/assets/` |
| `@client` | `assets/client/` (uniquement côté client) |

Quand un module est ajouté côté core, ajouter l'alias dans `aliases.js`
puis lancer `make sync-jsconfig` côté client pour propager.

---

## Composants partagés Aurora (`@shared`)

Toujours utiliser les composants `App*` d'Aurora plutôt que les éléments HTML bruts.

| Composant | Usage |
|---|---|
| `AppInput` | Champ texte (`<input>`) |
| `AppTextarea` | Zone de texte (`<textarea>`) |
| `AppSelect` | Liste déroulante (`<select>`) |
| `AppButton` | Bouton (`<button>`) |
| `AppDatePicker` | Sélecteur de date (jamais `type="date"` natif) |
| `AppModal` | Modale (API `:show` + `@close`, jamais `v-model:open`) |
| `AppCheckbox` | Case à cocher |
| `AppBadge` | Badge statut |

Import :

```vue
<script setup>
import AppInput from '@shared/components/AppInput.vue';
import AppButton from '@shared/components/AppButton.vue';
import AppModal from '@shared/components/AppModal.vue';
</script>
```

---

## Conventions Vue

### Directives

```vue
<!-- ✅ Correct -->
<button v-on:click="handleClick">...</button>
<div :class="{ active: isActive }">...</div>

<!-- ❌ Éviter -->
<button @click="handleClick">...</button>
```

### Privacy dans les composables

```js
// ✅ Variable module-level non exportée (pas de préfixe _)
const cache = new Map();

export function useMyComposable() {
  // ...
}
```

```js
// ✅ Champs privés dans les classes
class MyService {
  #config;
  constructor(config) { this.#config = config; }
}
```

### Formulaires (`editForm`)

`editForm` ne doit contenir **que** les champs envoyés au backend :

```js
// ✅ Correct
const editForm = reactive({
  title: '',
  description: '',
});

// Submit
await request(url, { ...editForm });  // spread - envoie tout
```

```js
// ❌ Éviter - champs parasites
const editForm = reactive({
  title: '',
  isLoading: false,    // état UI - ne pas mettre ici
  displayLabel: computed(() => editForm.title.toUpperCase()),  // computed - ne pas mettre ici
});
```

---

## Traductions Vue (`locales/`)

Les fichiers `assets/client/locales/{fr,en}.js` contiennent les labels
utilisés **uniquement côté Vue** (boutons, labels de permissions dans l'UI) :

```js
// assets/client/locales/fr.js
export default {
  tracking: {
    projects: {
      title: 'Projets',
      create: 'Nouveau projet',
    },
  },
  backend: {
    permissions: {
      names: {
        tracking: {
          projects: {
            manage: 'Gérer les projets',
            delete: 'Supprimer les projets',
          },
        },
      },
    },
  },
};
```

> **Important** : pour chaque `NavPermission('tracking.projects.manage')` déclaré
> dans `TrackingModule`, ajouter la clé `backend.permissions.names.tracking.projects.manage`
> en FR **et** EN dans les locales Vue. Sans ça, la permission s'affiche avec
> sa clé brute dans l'UI de gestion des droits.

---

## Overrides de composants Aurora

Pour remplacer un composant Aurora existant, créer un fichier
**co-localisé avec l'extension PHP** sous
`src/Module/<AuroraModule>/<Feature>/assets/` en miroir du chemin Aurora :

```
# Composant Aurora
vendor/axelraboit/aurora/src/Module/Platform/assets/backend/agencies/AgenciesApp.vue
                                 ↓
# Override client (co-localisé avec src/Module/Platform/Agency/ qui contient
# Entity/, Dto/, Manager/, Serializer/ de l'extension)
src/Module/Platform/Agency/assets/backend/agencies/AgenciesApp.vue
```

Le chemin `vue_component('platform/backend/agencies/AgenciesApp')` reste
identique - le glob `@client/src/Module/**/assets/**/*.vue` flatten les
feature folders, donc la clé exposée est la même que celle d'Aurora.
Comme `clientModules` est spread APRÈS `auroraModules`, ton fichier wins
automatiquement. Détail (règle des deux mirrors PHP/URL) :
[`convention_overrides_vs_modules.md`](../../../.claude/memory/aurora-client/convention_overrides_vs_modules.md).

---

## Lancer le dev serveur

```bash
make dev     # Vite en mode watch - rechargement à chaud
make build   # Build de production
```

En développement, Vite est lancé automatiquement par `make start`.

---

## ⚠️ Symlink `public/build` → vendor

Vite (lancé depuis `vendor/axelraboit/aurora/` via `make build` / `make
dev`) écrit ses chunks dans
`vendor/axelraboit/aurora/public/build/`. Mais le bundle
`pentatrion/vite-bundle` côté Symfony cherche `entrypoints.json` /
`manifest.json` dans `public/build/` à la racine du projet.

Le template aurora-client résout ce mismatch via un **symlink relatif
versionné en git** :

```
public/build → ../vendor/axelraboit/aurora/public/build
```

Le symlink est trackés dans aurora-client (`git ls-files public/build`
le montre, mode `120000`). Sur un clone direct → c'est en place
automatiquement.

### Symptômes si le symlink manque

- Page HTML rendue sans aucun `<script>` / `<link>` (assets pas inclus)
- Ou erreur Symfony : `vite manifest not found at public/build/manifest.json`
- Le `make build` rapporte un succès, mais `ls public/build/` retourne
  "No such file or directory"

### Restaurer le symlink

```bash
cd <project-root>
ln -s ../vendor/axelraboit/aurora/public/build public/build
```

### Pourquoi le symlink survit-il à `.gitignore` ?

Le `.gitignore` aurora-client a `/public/build/` (avec trailing slash),
qui ne matche que les **directories**. Git voit le symlink comme un
fichier (mode 120000), donc il échappe à la règle et reste trackable.
