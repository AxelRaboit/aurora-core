---
name: User sidemenu preferences (hide sections/items)
description: Préférence user-controlled pour cacher sections/items de la sidemenu, distinct du toggle module admin/dev
type: project
---
## Pattern

Une **troisième** couche de visibilité sidemenu, distincte des toggles existants :

| Couche | Stockage | Géré par | Affecte |
|---|---|---|---|
| Global module toggle | `core_settings` | Dev panel | Tous les users |
| User module mask | `core_users.disabled_modules` (JSON) | Admin / dev panel | Un user (admin override) |
| **User sidemenu prefs** | `core_users.hidden_nav_sections` + `core_users.hidden_nav_items` (JSON) | L'utilisateur lui-même | Sa propre sidemenu |

**Sémantique stricte** : "hidden" = retiré de la sidemenu uniquement. Les
routes restent accessibles par URL directe. Ce n'est **pas** une couche de
sécurité (≠ `disabledModules` qui est un access mask).

## Tokens stables (règle dure)

- `NavSection::$id` = identifiant technique immuable (ex: `'crm'`,
  `'billing.invoicing'`). Sert de clé pour `hiddenNavSections`.
- `NavItem::$route` = nom de route Symfony, sert de clé pour `hiddenNavItems`.

**Renommer** un `id` de section ou une route ⇒ breaking change : les
préférences user pointant dessus deviennent silencieusement orphelines
(filtrées au sanitize). À traiter comme une migration de données si
nécessaire.

**Why:** Empêcher que les préférences utilisateur se perdent à chaque
refacto cosmétique du sidemenu. Documenté dans les docblocks de
`NavSection` et `NavItem`.

## Filtrage

`ModuleRegistry::getNavSections()` lit le user courant via `Security` et
filtre sections/items hidden. Les items résolus exposent deux champs
distincts :
- `key` = `$item->route` (token stable, identifiant pour le hide)
- `route` = `$item->activeRoutePrefix ?? $item->route` (utilisé pour le
  highlight d'état actif côté Vue)

`ModuleRegistry::getNavPreferences()` retourne la même structure mais
**sans** filtrer les hidden - chaque entrée porte un flag `hidden: bool`.
Sert exclusivement à la page de préférences (pour pouvoir un-hide).

## How to apply

- Ne **jamais** renommer un `NavSection::$id` ou une route servant de
  `NavItem::$route` sans migration des préférences user. Préférer
  ajouter/supprimer plutôt que renommer.
- Pour ajouter une nouvelle entrée nav : pas de changement requis, elle
  apparaît automatiquement et peut être hidden via la page de prefs.
- Validation à l'écriture : `UserManager::updateSidemenuPreferences()`
  filtre l'input contre `getNavPreferences()` (intersection). Les tokens
  inconnus / hors privilege sont droppés silencieusement.

## Lieux clés

- Colonnes : `AbstractUser::$hiddenNavSections` + `$hiddenNavItems`
- Interface : `CoreUserInterface::getHidden{NavSections,NavItems}()`
- Migration : `Version20260515091752.php`
- Filtrage : `Aurora\Core\Module\Service\ModuleRegistry`
- Manager hooks : `UserManager::updateSidemenuPreferences()` + `resetSidemenuPreferences()`
- Audit : `AuditUserManagerDecorator` (event `user.sidemenu_preferences_updated`)
- Endpoints : `GET/POST /backend/general/profile/sidemenu`, `POST /backend/general/profile/sidemenu/reset`
- Vue : `preferences/PreferencesApp.vue` (shell à onglets) +
  `preferences/tabs/SidemenuTab.vue` (1er onglet) +
  `composables/useSidemenuPreferences.js`
- Twig : `src/Module/General/templates/backend/profile/sidemenu.html.twig`
  (le profile vit dans `Module/General` depuis le rollout 0.4 ; le namespace
  Twig est `@General/backend/profile/sidemenu.html.twig`)

Voir aussi [[pattern_user_scoped_module_access]] pour la couche admin
(distincte).
