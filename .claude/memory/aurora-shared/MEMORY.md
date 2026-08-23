# Aurora-shared - index mémoire (distribué via aurora-core + aurora-client)

Ce dossier contient les conventions **transversales** : utiles aussi bien pour un
développeur aurora-core que pour un développeur aurora-client (nouveau module,
formulaire Vue, appels HTTP, commits, etc.).

Distribué via composer : les clients lisent ces mémoires depuis
`vendor/axelraboit/aurora/.claude/memory/aurora-shared/`.

---

## Architecture / responsabilités (règles dures)

- [convention_no_cross_module_dep.md](convention_no_cross_module_dep.md) -
  **règle dure** : pas d'import entre modules **du consommateur**
  (`App\Module\Tracking` → `App\Module\Bnb`). Le câblage passe par des
  points d'extension typés (`extraXxx` props/DTO) remplis par le projet.
  **Ne s'applique pas** aux sept modules d'aurora-core, qui forment un seul
  noyau au couplage assumé jusque dans les entités - ne pas les relever en
  audit.
- [convention_edit_vs_update_route_naming.md](convention_edit_vs_update_route_naming.md) -
  **règle dure** : `_edit` = GET page Twig d'édition ; `_update` = POST API
  JSON. Jamais un seul nom pour les deux. CRUD SPA inline = seulement
  `_update` (pas de page `_edit` car édition vit dans une modal de l'index).
- [convention_service_final_vs_readonly.md](convention_service_final_vs_readonly.md) -
  **règle dure** : services purs `final readonly`, mais thin shells
  (notifications, webhooks, audit) juste `readonly` pour rester
  mock-ables en unit test. Repos jamais `final`. Matrice de décision
  incluse + commande de détection des outliers.
- [convention_thin_controller.md](convention_thin_controller.md) - **règle dure** :
  controllers ultra-fins (routing + auth + DTO + délégation + sérialisation).
  Toute logique métier dans Manager (mutations) ou Service (calcul pur). À
  répéter à chaque session.
- [convention_domain_exception_translation_key.md](convention_domain_exception_translation_key.md) -
  pour les rejets métier surfacés en JSON : `DomainException` dédiée +
  constante `TRANSLATION_KEY`. Anti-pattern explicite : `str_starts_with`
  sur `$e->getMessage()`.
- [convention_sfc_thin_presentation.md](convention_sfc_thin_presentation.md) -
  **règle dure** : les SFC `.vue` restent fines (template + bindings + refs
  UI-only), toute logique métier (état orchestré, watchers, autosave, HTTP,
  state machine) va dans un composable co-localisé. Limite : ~80 lignes de
  `<script setup>`.
- [convention_mirrored_contract_php_js.md](convention_mirrored_contract_php_js.md) -
  une liste de valeurs écrite des deux côtés (normaliseur PHP + composable JS)
  doit être tenue par un test PHP qui **lit le fichier JS**. Un commentaire
  « Mirrors X » n'est pas une contrainte. Référence :
  `GridContractMirrorTest`, qui a trouvé une divergence à sa première
  exécution.
- [convention_storage_var_uploads.md](convention_storage_var_uploads.md) -
  **règle dure** : tout fichier stocké vit sous `var/uploads/<categorie>/`,
  jamais `public/`. Servi exclusivement via PHP (`BinaryFileServer`).
  URL building via service dédié injecté, jamais hardcodé dans les
  entités. Apache `mod_xsendfile` offload en prod.
- [convention_naming.md](convention_naming.md) - **règle dure** :
  `kebab-case` pour ce qui est lu par un humain (URL, folder assets,
  CSS), `snake_case` pour les identifiants internes (route name,
  setting, DB column, i18n), `PascalCase` pour classes/composants,
  `camelCase` pour JS. Doc canonique : CLAUDE.md §4 + cette mémoire.

## Vue / composants

- [convention_form_components.md](convention_form_components.md) - toujours
  `App*` (AppButton, AppInput, AppSelect…) - jamais `<button>` / `<input>` bruts ;
  asterisk = required (jamais « (optionnel) » dans un label) ; placeholder
  obligatoire ; AppDatePicker pour dates ; DTO ↔ UI required miroir ; `:error` bindé
- [convention_vue_directives.md](convention_vue_directives.md) - toujours
  `v-on:` (forme longue) pour les events, jamais `@` ; `:` reste OK pour v-bind
- [convention_mobile_card_layout.md](convention_mobile_card_layout.md) - toute
  liste CRUD = sm:hidden cards + hidden sm:block table + footer d'actions
- [pattern_admin_list_toolbar.md](pattern_admin_list_toolbar.md) - toolbar
  standard (search + boutons) via `<AppListToolbar>` slot-par-défaut + `#actions`
- [convention_multi_button_toolbar.md](convention_multi_button_toolbar.md) - 2+
  boutons dans `#actions` = wrapper `flex flex-col sm:flex-row gap-2 w-full sm:w-auto`
- [convention_modal_and_confirmation.md](convention_modal_and_confirmation.md) -
  AppModal API + confirmation de suppression via modale (jamais `confirm()` natif)
- [convention_vue_form_validation.md](convention_vue_form_validation.md) -
  `useForm` + `required()` de validators + `:error` sur chaque AppInput validé
- [convention_app_loader.md](convention_app_loader.md) - toute liste paginée =
  `<div class="relative space-y-N">` + `<AppLoader :active="loading" />`
- [pattern_cross_mount_state_sync.md](pattern_cross_mount_state_sync.md) -
  synchroniser deux mounts Vue indépendants sans reload : CustomEvent
  global + composable qui wrap la prop dans un ref + event listener.
  Anti-pattern : `window.location.reload()` après save.

## HTTP / fetch

- [convention_no_raw_fetch.md](convention_no_raw_fetch.md) - interdiction de
  `fetch()` brut ; toujours `useRequest` (admin) ou `useFrontendRequest` (public)
  (une exception : la sauvegarde de préférence sans attente)
- [convention_xhr_header.md](convention_xhr_header.md) - `useRequest` envoie
  `X-Requested-With: XMLHttpRequest` ; les controllers Symfony détectent les XHR
  via ce header pour retourner JSON

- [pitfall_display_contents.md](pitfall_display_contents.md) - `AppTooltip` a
  `display: contents` pour racine : les marges y sont ignorées (`space-y-*` ne
  rend rien) et `first:`/`last:` matchent chaque ligne. Mettre en page avec
  `gap`, positionner depuis l'index.
- [convention_chart_palette.md](convention_chart_palette.md) - palette
  catégorielle fixe `--chart-cat-1..8`, ordre non recyclé, part-d'un-tout =
  barre empilée et jamais camembert, valeur lisible en texte sous 3:1.
## JS

- [convention_no_em_dash.md](convention_no_em_dash.md) - jamais de cadratin
  (`U+2014`), nulle part : ni texte affiché, ni commentaire, ni doc. Tableau des
  remplacements par fonction dans le fichier.
- [convention_js_no_var.md](convention_js_no_var.md) - toujours `const`/`let`,
  jamais `var`
- [convention_js_privacy.md](convention_js_privacy.md) - `#` pour les classes
  ES2022 ; variables module-level non exportées = déjà privées, pas de `_`

## i18n

- [convention_i18n_key_casing.md](convention_i18n_key_casing.md) - **toutes** les
  clés de traduction en `snake_case`, sans exception. `camelCase` interdit et
  cassé en silence (lookup exact vue-i18n/Symfony). Inclut méthode d'audit
- [convention_locale_options.md](convention_locale_options.md) - importer
  `LOCALE_OPTIONS` depuis `@core/utils/locales.js` ; locales supportées : `fr` et `en`
- [convention_ui_copy_tone.md](convention_ui_copy_tone.md) - copy UI impersonnel
  (pas de tu/vous, pas de nom de marque, pas d'emojis dans le texte). S'applique
  aux AppMessage helpers, empty states, page subtitles

## Process / commits

- [process_check_aurora_client_sync.md](process_check_aurora_client_sync.md) -
  **règle dure** : après toute modif d'aurora-core, vérifier que aurora-client
  reste à jour : routes préservées, overrides toujours valides, nouvelles
  conventions appliquées, breaking changes testés côté client. À répéter à
  chaque session.
- [process_never_patch_vendor.md](process_never_patch_vendor.md) - **règle
  dure** : `vendor/axelraboit/aurora*` est en lecture seule côté client. Passer
  par commit → push → `composer update`, et committer le `composer.lock`. Un
  vendor patché fait passer le pipeline local tout en cassant la CI.
- [process_make_ft_before_commit.md](process_make_ft_before_commit.md) -
  lancer `make ft` (= fix + test) avant chaque commit ; aucune échappatoire
- [pitfall_mapping_index_schema_drift.md](pitfall_mapping_index_schema_drift.md) -
  un index ou un type de colonne créé par une migration mais non déclaré dans le
  mapping est un diff **permanent** de `schema:update`. Invisible sur une base de
  dev bruitée : le contrôle se fait chez un consommateur propre, où la sortie
  attendue est « Nothing to update ».
- [pref_no_co_authored.md](pref_no_co_authored.md) - ne jamais ajouter
  `Co-Authored-By: Claude` dans les commits
- [pref_commit_language.md](pref_commit_language.md) - messages de commit
  toujours en anglais
- [pref_french_dialogue.md](pref_french_dialogue.md) - dialogue conversationnel
  en français, code et commits en anglais
- [pref_think_long_term.md](pref_think_long_term.md) - **inverse de YAGNI** :
  anticiper les abstractions SOLID dès qu'elles sont saines, même sans
  utilisateur concret immédiat. Garde-fous explicites contre
  l'over-engineering aveugle. S'applique core ET client.

---

## Règles d'usage

- **Lecture** : ouvrir le fichier source, ne pas se reposer sur le résumé seul.
- **Écriture** : si une convention émerge qui s'applique aux deux contextes
  (core + client), la créer ici plutôt que dans aurora-core ou aurora-client.
- **Sync** : après tout ajout/modif, lancer `make sync-claude-memory`.
