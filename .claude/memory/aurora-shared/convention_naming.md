---
name: convention-naming
description: Filesystem + identifier naming across the Aurora ecosystem. kebab-case for human-facing names (URLs, asset folders, CSS), snake_case for internal keys (routes, settings, DB, i18n), PascalCase for classes/components, camelCase for JS values.
metadata:
  type: feedback
---

**Règle d'or** (heuristique mnémotechnique) :

> **Lu par un humain dans une URL ou dans le filesystem** → `kebab-case`
> **Identifiant interne** (route, setting, colonne DB, traduction) → `snake_case`
> **Classe PHP / composant Vue** → `PascalCase`
> **Variable / fonction JS** → `camelCase`

## Application par type

| Type | Format | Exemple |
|---|---|---|
| PHP class / namespace | `PascalCase` | `MarkdownNoteManager`, `Aurora\Module\Notes\Markdown\Service` |
| Vue component | `PascalCase.vue` | `MarkdownNotesApp.vue` |
| JS composable / util | `camelCase.js` | `useNoteImageUpload.js` |
| CSS class | `kebab-case` | `.note-image-wrap` |
| Folder dans `assets/` ou `templates/` | `kebab-case` | `Module/Ecommerce/backend/listing-categories/` |
| URL path | `kebab-case` | `/backend/platform/access-request`, `/uploads/media/...` |
| Symfony route name | `snake_case` | `backend_media_media_upload`, `uploads_serve` |
| Setting / i18n / DB key | `snake_case` | `notes_markdown_image_max_edge` |
| Doctrine table / sequence | `snake_case` (`seq_core_<entity>_id`) | `seq_core_markdown_note_id` |
| Doc folder | `kebab-case` | `getting-started/`, `entity-extensibility/` |

## Cas particulier : `vue_component('<module>/backend/...')` Twig

Le helper Twig `vue_component()` prend le **nom du module folder en lowercase
compact** (sans tiret) comme premier segment, **pas** kebab-case :

| Module folder | `vue_component()` prefix | URL backend (kebab-case) |
|---|---|---|
| `Notes` | `notes/backend/...` | `/backend/notes/...` |
| `PdfForm` | `pdfform/backend/...` | `/backend/pdf-form/...` |
| `PasswordGenerator` | `passwordgenerator/backend/...` | `/backend/password-generator/...` |
| `PersonalFinance` | `personalfinance/backend/...` | `/backend/personal-finance/...` |
| `Vault` | `vault/backend/...` | `/backend/vault/...` |

Pourquoi : le résolveur Vue (`@symfony_ux-vue`) construit ses clés à partir
du glob `import.meta.glob('./Module/**/*.vue')` et applique `strtolower()`
sur le nom du folder Module - sans transformation kebab. Conséquence : le
nom Vue côté Twig **ne suit pas** la convention URL.

Les segments **après** `<module>/backend/` reflètent le chemin réel sous
`assets/backend/` (kebab-case ou single-word lowercase selon le folder).

### Anti-pattern fréquent

❌ `vue_component('personal-finance/backend/wallet/PersonalFinanceWalletsApp')`
    → erreur runtime "Vue controller does not exist"
✅ `vue_component('personalfinance/backend/wallet/PersonalFinanceWalletsApp')`

Le piège vient du fait que l'URL est `/backend/personal-finance/...` (kebab)
mais que la référence Vue est `personalfinance/...` (compact). Les deux
cohabitent pour le même module - c'est inhabituel mais c'est la règle.

## Anti-patterns

❌ `src/Module/Crm/assets/backend/contact_tags/` → ✅ `contact-tags/`
❌ `docs/aurora-client/getting_started/` → ✅ `getting-started/`
❌ Mixer kebab et snake dans un même type sur des modules différents
❌ URL avec underscore (`/forgot_password`) au lieu de dash (`/forgot-password`)
❌ Setting key avec dash au lieu d'underscore
❌ `vue_component('personal-finance/...')` au lieu de `personalfinance/...` (cf. cas particulier ci-dessus)

## Doc canonique

Cette mémoire **est** la doc canonique (le fichier `docs/.../naming_convention.md`
de l'ancien temps a été supprimé pour éviter le double-emploi avec CLAUDE.md §4).

## Why

- **Cohérence cross-module** : un dev qui ouvre `Ecommerce/` doit voir
  le même style que `Ged/` ou `Editorial/`.
- **URLs publiques** : `kebab-case` est la convention web universelle
  (Stripe, GitHub, MDN…).
- **Identifiants internes** : `snake_case` est la convention SQL +
  PHP / YAML / settings.
- **Outillage** : grep / IDE fuzzy find / autocomplétion plus
  prévisibles quand la casse est uniforme.

S'applique à **aurora-core ET aurora-client** - quand un client ajoute
un module / un dossier asset / un setting, il suit la même grille.

Lié à [[pref_think_long_term]] (la cohérence cross-écosystème fait
partie de la philosophie "penser long terme").
