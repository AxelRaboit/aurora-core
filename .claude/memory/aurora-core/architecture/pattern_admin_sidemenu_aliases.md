---
name: Admin-wide sidemenu aliases (rename sections + items)
description: Settings JSON `nav_section_aliases` + `nav_item_aliases` permettent à l'admin de renommer sections/items du menu latéral sans toucher au code.
metadata:
  type: project
---

## Pattern

Deux settings JSON dans `core_settings`, gérés via l'onglet *Navigation* de
`/backend/configuration/settings` :

| Setting | Clé d'index | Affecte |
|---|---|---|
| `nav_section_aliases` | `NavSection::$id` (ex: `'crm'`) | Tous les users, tous les rendus du menu |
| `nav_item_aliases` | `NavItem::$route` (nom de route Symfony) | Idem |

Format : `{key: "Custom Label"}`. Valeur vide / absente ⇒ fallback au
`t(labelKey)` (sections : `backend.nav.sections.<id>`).

**Distinct de [[pattern_user_sidemenu_preferences]]** qui est per-user et
gère la *visibilité* (hide). Ici : admin-wide + renommage uniquement.
Pas de collision : les deux couches s'appliquent indépendamment.

## Tokens stables

Mêmes que les préférences user : `NavSection::$id` et `NavItem::$route`.
Renommer ⇒ alias devient orphelin (silencieusement ignoré au rendu, mais
reste dans le JSON ⇒ pollution mineure). Cf docblock de `NavItem` qui
documente `$route` comme stable token.

## Why

Permettre la personnalisation des libellés sans patcher les fichiers de
traduction ou forker aurora-core. Cas usage : multi-tenant qui veut
appeler "Clients" ce qu'aurora nomme "CRM", ou simplifier le wording pour
des utilisateurs non-techniques.

## How to apply

- **Nouvelle entrée admin-wide** (renommage globalement visible) ⇒ ajouter
  via UI Settings, ne **pas** modifier `messages.fr.yaml`.
- **Override locale-aware** (un libellé par langue) ⇒ hors v1 : prévoir un
  nouveau setting `nav_*_aliases_by_locale` distinct.
- **Items avec children** : v1 alias parent uniquement (children non
  éditables). À étendre si besoin d'aliasing au niveau enfant.

## Lieux clés

- Enum : `ApplicationParameterEnum::NavSectionAliases`,
  `ApplicationParameterEnum::NavItemAliases` (group `'navigation'`)
- Twig functions : `SidemenuExtension::getNavSectionAliases()`,
  `getNavItemAliases()`
- Twig consumers : `src/Core/templates/Core/backend/layout.html.twig` (sidemenu admin),
  `src/Module/General/templates/backend/profile/sidemenu.html.twig` (preferences page)
- Vue composables : `useSidemenuNav.js` (4e param `itemAliases`),
  `useSidemenuPreferences.js` (param `itemAliases` + `resolveItemLabel()`)
- Settings UI logic : `useNavAliases.js` (composable dédié - la logique
  parse/save/reset ne vit **pas** dans `SettingsApp.vue`)

## Convention de placement (rappel)

Toute logique métier (fetch, parse, save, mutation d'état) liée à un
formulaire Settings doit aller dans un composable dans
`src/Core/assets/backend/settings/composables/`. Le SFC `SettingsApp.vue` reste
un orchestrateur de template (cf. `useSettingsForm`, `useSettingsPostPicker`,
`useSettingsSequenceFilter`, `useNavAliases`).
