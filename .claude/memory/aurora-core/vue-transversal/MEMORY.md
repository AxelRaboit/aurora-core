# Vue / JS - Transversal (admin + public)

- [convention_testing.md](convention_testing.md) - co-location obligatoire `.test.js` à côté de la source ; patterns composant/composable/util ; stubs courants ; ce qu'on skip
- [convention_form_components.md](convention_form_components.md) - toujours `App*` (AppButton, AppInput, AppRange, AppPagination…) jamais HTML brut - **admin ET frontend public**
- [convention_button_variants.md](convention_button_variants.md) - variants + size + icônes des boutons d'action : modal footer `ghost/primary/danger`, page header Edit en `secondary` (pas ghost), `size="md"` explicite, icônes `w-3.5 h-3.5 stroke-width=2`, label visible
- [convention_vue_directives.md](convention_vue_directives.md) - `v-on:click` (jamais `@click`), `:` shorthand OK pour `v-bind`
- [convention_js_no_var.md](convention_js_no_var.md) - toujours `const`/`let`, jamais `var` (modules ES, scripts inline Twig, partout)
- [convention_js_privacy.md](convention_js_privacy.md) - `#field` dans les classes (jamais `_field`), variable module-level non exportée
- [convention_i18n_source_files.md](convention_i18n_source_files.md) - éditer les YAML sources, jamais le JSON généré. `make translation` régénère
- [convention_i18n_key_casing.md](convention_i18n_key_casing.md) - **toutes** les clés de traduction en `snake_case`, sans exception. `camelCase` interdit, cassé en silence (lookup exact). Inclut méthode d'audit
- [convention_i18n_plurals.md](convention_i18n_plurals.md) - **vue-i18n syntaxe pipe** (`'1 X | {count} Xs'`) jamais ICU `{count, plural, …}`. 3e arg de `t()` = count pour la sélection d'arm
- [convention_no_raw_fetch.md](convention_no_raw_fetch.md) - jamais `await fetch()` brut → `useRequest` (admin) ou `useFrontendRequest` (public)
- [structure_assets_vue.md](structure_assets_vue.md) - composants Vue, composables, naming, `frontend/components/` + `frontend/composables/`, anti-patterns
- [convention_assets_subfolder_layout.md](convention_assets_subfolder_layout.md) - compartimentage feature-subfolder dans `src/Module/<M>/assets/backend/`
- [convention_css_organization.md](convention_css_organization.md) - `src/Core/assets/css/{base,shared,core,modules/<Name>/}` ; importer le CSS dans le SFC (code-splitting) sauf si vraiment global → `app.css` ; ordre `<script setup>` : CSS d'abord, ligne vide, JS
- [composable_hierarchical_tree.md](composable_hierarchical_tree.md) - `@/shared/composables/tree/useHierarchicalTree.js` (`buildTree`, `flattenTreeForReorder`, …) - ne pas dupliquer
- [composable_client_filtered_list.md](composable_client_filtered_list.md) - `useClientFilteredList` pour les listes admin courtes (items + searchInput + filteredItems + reload), pendant client-side de `useListPage`
- [convention_twig_locale_extension.md](convention_twig_locale_extension.md) - `locale_flag()` / `locale_name()` Twig depuis `LocaleExtension.php`
- [pattern_settings_tab_vue_registry.md](pattern_settings_tab_vue_registry.md) - `registerSettingsTabComponent()` : plugin pattern pour fournir un composant Vue custom à un onglet de la page Settings (côté Vue de l'extension PHP `ConfigurationTabProvider`)
- [pattern_display_timezone_shift.md](pattern_display_timezone_shift.md) - dessiner des instants UTC dans un fuseau choisi sans réécrire l'arithmétique de dates : réécrire l'instant en horloge murale **sans offset**, que `new Date()` relit en local (`toDisplay` / `fromDisplay`). Deux corollaires durs : ne jamais envoyer un `Date` d'affichage au serveur, et jamais `toISOString()` pour une clé de jour.
