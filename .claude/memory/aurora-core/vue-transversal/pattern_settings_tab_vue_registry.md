---
name: pattern-settings-tab-vue-registry
description: registerSettingsTabComponent() - comment fournir un composant Vue custom pour un onglet de la page admin Settings, depuis aurora-core ou aurora-client.
metadata:
  type: project
---

## Règle

La page admin Settings dispatche le rendu d'un onglet via le registre Vue
`src/Core/assets/backend/settings/tabRegistry.js` :

- **Côté PHP** : un `ConfigurationTab` peut déclarer `componentName: 'foo'`.
- **Côté Vue** : `tabRegistry.js` mappe `'foo'` → un composant Vue.
- **`SettingsApp.vue`** : pour chaque tab visible, si `componentName` est
  enregistré, rend `<component :is=...>` avec les props standards ; sinon,
  utilise le field renderer générique.

API publique exposée par `tabRegistry.js` :

```js
import { registerSettingsTabComponent } from "@core/backend/settings/tabRegistry.js";
import MyCustomTab from "./MyCustomTab.vue";

registerSettingsTabComponent("my-tab-name", MyCustomTab);
```

Les composants reçoivent les props suivantes (passées par `SettingsApp.vue`) :
- `groups` : `Record<tabId, fieldDescriptor[]>` - payload complet.
- `updatePath` : endpoint POST pour persister les writes.
- `navSections` : metadata sidemenu (utile pour navigation aliases).
- `postSearchPath` : endpoint de recherche de posts.

Composants built-in pré-enregistrés par aurora-core : `navigation`
(`NavigationTab.vue`), `appearance` (`AppearanceTab.vue`).

## Pourquoi

Avant Phase C, `SettingsApp.vue` faisait du `v-show="activeTab === 'navigation'"`
hardcodé pour deux onglets à UI custom. Impossible pour un client (ou
même un autre module aurora-core) d'ajouter son propre onglet à UI riche
sans patcher ce fichier.

Le pattern plugin (`register…()`) a été choisi sur 3 alternatives :
- ❌ Map globale `window.__auroraSettingsTabs` : cache mutable, ordre de
  chargement fragile, pas typé.
- ❌ Vite alias / virtual module override : demande config build,
  moins explicite, doc plus chargée.
- ✅ Plugin pattern `registerSettingsTabComponent()` : API explicite, typée,
  découverte facile via IDE, contrôle d'inversion clair (le client pousse).

Voir aussi [[pattern_configuration_tab_provider]] pour le côté PHP de
l'extension.

## Comment l'appliquer

**Côté aurora-client** : dans le bootstrap Vue (entrypoint `main.js` ou
équivalent, avant le mount du SettingsApp) :

```js
import { registerSettingsTabComponent } from "@core/backend/settings/tabRegistry.js";
import MyCustomTab from "@/MyCustomTab.vue";

registerSettingsTabComponent("my-tab", MyCustomTab);
```

**Côté PHP (provider du client)** : déclarer le `componentName` matching :

```php
return [
    new ConfigurationTab(
        id: 'my-tab',
        priority: 130,
        fields: [],
        alwaysVisible: true,
        componentName: 'my-tab',
    ),
];
```

**Anatomie d'un composant tab** : prendre comme template
`NavigationTab.vue` ou `AppearanceTab.vue` dans
`src/Core/assets/backend/settings/tabs/`. Définir les props attendues, encapsuler
toute la logique de save via un composable local consommant `updatePath`,
ne pas leak d'état dans `SettingsApp.vue`.

**Merge-by-id** : si plusieurs providers contribuent un même tab id mais
qu'un seul fournit `componentName`, le merge dans `SettingDefinitionRegistry`
conserve le premier `componentName` non-null trouvé (ordre des providers).
Test : `SettingDefinitionRegistryTest::test_merges_tabs_sharing_an_id…`.
