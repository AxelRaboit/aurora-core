# Namespacing des routes backend par module - décision + backlog

## Règle

**Toute** route backend est préfixée par son module : `/backend/<module>/<entité>`
+ nom de route `backend_<module>_<entité>`. Le path ET le nom suivent le
namespacing (cf. [[structure_controller]]).

**Décision user (2026-05) : on namespace TOUT**, sans exception "core/transverse
reste à plat". Donc aussi l'auth et les pages transverses :
- `/backend/platform/users` → `/backend/platform/users`
- `/backend/configuration/settings`, `/backend/configuration/themes` → `/backend/configuration/…`
- `/backend/platform/login`, `/backend/platform/register`, `/backend/platform/forgot-password` → `/backend/platform/…`
- `/backend/general/profile`, `/backend/general/search` → `/backend/general/…`

## Pourquoi

Editorial était le **premier module** du projet et avait gardé des URLs à plat
(`/backend/editorial/posts`), comme plusieurs modules historiques. La majorité des modules
récents (Crm, Ged, Ecommerce, Erp, Billing, Vault, PersonalFinance, Assistant,
Notes) namespacent déjà. Le user veut l'uniformité totale plutôt que des
exceptions au cas par cas.

## Comment l'appliquer

Méthode rodée sur Editorial (commit `17890cb2`) :
1. Sur chaque controller backend du module : changer le `#[Route]` de **classe**
   (path + `name:`). Les routes de méthode héritent du préfixe.
2. Remplacer toutes les références de nom de route (`path()`, `redirectToRoute`,
   `generateUrl`, nav `getNavSections`) - PHP + Twig.
3. URLs **hardcodées** en JS (rares) : remplacer en ancrant sur un délimiteur
   de chaîne (`'`, `"`, backtick) **avant** `/backend/` pour ne PAS casser les
   alias d'import Vite `@<module>/backend/…` (qui contiennent aussi `/backend/`).
4. Tests : passer par `urlGenerator->generate('backend_<module>_…')` plutôt que
   des URLs en dur (cf. `FormsControllerTest`), + `HttpMethodEnum`.
5. `cache:clear` + `debug:router` (0 ancien nom) + `npm run build` + `phpunit` +
   `make fix`. Commit atomique par module.

## Backlog (état) - ✅ TERMINÉ (2026-05)

- ✅ **Editorial** : posts, post-types, forms, comments, taxonomies, menus, sitemap.
- ✅ **Photo** : galleries → `/backend/photo/galleries`.
- ✅ **Project** : projects → `/backend/project/projects`.
- ✅ **Planning** : plannings → `/backend/planning/plannings`.
- ✅ **General** : profile, search → `/backend/general/*`.
- ✅ **Configuration** : settings, themes → `/backend/configuration/*`.
- ✅ **Media** : media → `/backend/media/media`.
- ✅ **Platform** : agencies, users, services + **toute l'auth** (login, logout,
  register, forgot/reset-password, verify-email, access-request, invitation,
  resend-verification, impersonate) → `/backend/platform/*`. `security.yaml`
  (firewall login_path/check_path/logout + access_control) mis à jour.
- Déjà namespacés (n'ont pas bougé) : Crm, Ged, Ecommerce, Erp, Billing, Vault,
  PersonalFinance, Assistant, Notes.

### Exceptions assumées (restent à plat)

- **`backend_dashboard` = `/backend`** : c'est le home backend ; le namespacer
  (`/backend/general/dashboard`) laisserait la racine sans page. Laissé tel quel.

### Module Tools (Outils) - Vault + PasswordGenerator

"Outils" est un **module conteneur** (`src/Module/Tools/`, `Aurora\Module\Tools`)
qui regroupe des outils utilitaires **indépendants**, exactement comme Notes
regroupe Markdown/Block/Post-it. Pattern : `ToolsModule` + `ToolsContext`
(isBackendEnabled / isVaultEnabled / isPasswordGeneratorEnabled), sous-features
en dossiers `Tools/Vault/` + `Tools/PasswordGenerator/`, templates/assets/trads
au niveau module, alias Vite `@tools`.
- Toggles : `ToolsBackend` (+ sous-toggles `ToolsVault`, `ToolsPasswordGenerator`).
- Permissions : `tools.vault.use`, `tools.password_generator.use`.
- URLs : `/backend/tools/vault`, `/backend/tools/password-generator` (routes
  `backend_tools_*`). Section nav `tools` (label "Outils"/"Tools").
- Le **coffre-fort** (`Vault`, entités VaultEntry/VaultFolder/VaultUserConfig +
  crypto) n'est qu'**un outil parmi d'autres** sous Tools, pas le conteneur.
- Historique : tentative de découplage (2 modules + merge de sections nav +
  group dashboard) annulée comme sur-ingénierie ; refait proprement à la Notes.
  Cf. [[feedback_prefer_existing_pattern]].

## Suites structurelles - ✅ FAIT (2026-05)

- ✅ **Dette nav-trads** : `backend.nav.*` (sections + items Platform/General/
  Configuration/Media + chrome sidemenu) déplacé de `Editorial/Menu/translations/`
  vers `Core/translations/` (commit i18n). `galleries` non déplacé (Photo le
  possède). `Editorial/Menu` ne garde que `backend.menus.*` + `frontend.menu.*`.
  General/Platform/Media/Configuration/Dev sont des **modules Core** (cf.
  [[pattern_core_submodules_split]]) → leurs libellés nav appartiennent bien à Core.
- ✅ **Split PostType** : `PostType`/`PostTypeField` (22 classes) extraits de
  `Editorial/Post/` vers `Editorial/PostType/` (namespace dédié). Convention
  "1 entité CRUD = 1 sous-domaine" (cf. Crm/ContactTag). Réfs croisées
  Post↔PostType explicitées par `use`. Mapping Doctrine auto-couvre le chemin.
