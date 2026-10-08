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
| Folder dans `assets/` ou `templates/` | `kebab-case` | `Module/Ecommerce/suite/listing-categories/` |
| URL path | `kebab-case` | `/suite/platform/access-request`, `/uploads/media/...` |
| Symfony route name | `snake_case` | `suite_media_media_upload`, `uploads_serve` |
| Setting / i18n / DB key | `snake_case` | `notes_markdown_image_max_edge` |
| Doctrine table / sequence | `snake_case` (`seq_core_<entity>_id`) | `seq_core_markdown_note_id` |
| Doc folder | `kebab-case` | `getting-started/`, `entity-extensibility/` |

## Cas particulier : `vue_component('<module>/suite/...')` Twig

Le helper Twig `vue_component()` prend le **nom du module folder en lowercase
compact** (sans tiret) comme premier segment, **pas** kebab-case :

| Module folder | `vue_component()` prefix | URL backend (kebab-case) |
|---|---|---|
| `Notes` | `notes/suite/...` | `/suite/notes/...` |
| `PdfForm` | `pdfform/suite/...` | `/suite/pdf-form/...` |
| `PasswordGenerator` | `passwordgenerator/suite/...` | `/suite/password-generator/...` |
| `PersonalFinance` | `personalfinance/suite/...` | `/suite/personal-finance/...` |
| `Vault` | `vault/suite/...` | `/suite/vault/...` |

Pourquoi : le résolveur Vue (`@symfony_ux-vue`) construit ses clés à partir
du glob `import.meta.glob('./Module/**/*.vue')` et applique `strtolower()`
sur le nom du folder Module - sans transformation kebab. Conséquence : le
nom Vue côté Twig **ne suit pas** la convention URL.

Les segments **après** `<module>/suite/` reflètent le chemin réel sous
`assets/suite/` (kebab-case ou single-word lowercase selon le folder).

### Anti-pattern fréquent

❌ `vue_component('personal-finance/suite/wallet/PersonalFinanceWalletsApp')`
    → erreur runtime "Vue controller does not exist"
✅ `vue_component('personalfinance/suite/wallet/PersonalFinanceWalletsApp')`

Le piège vient du fait que l'URL est `/suite/personal-finance/...` (kebab)
mais que la référence Vue est `personalfinance/...` (compact). Les deux
cohabitent pour le même module - c'est inhabituel mais c'est la règle.

## Une dépendance déclarée porte le nom de ce qu'elle **est**

**Rule:** une propriété ou un paramètre promu dont le type finit par un
suffixe de rôle (`Repository`, `Manager`, `Generator`, `Service`, `Provider`,
`Builder`, `Client`, `Formatter`, `Resolver`, `Normalizer`, `Parser`,
`Logger`, … 72 en tout) porte ce rôle dans son nom.

```php
// non : le nom annonce la donnée que le service rend
private MarkdownNoteRepository $notes,
private PathTemplateGenerator $pathTemplates,
private SiteDateFormatter $dates,

// oui : le nom dit ce que c'est
private MarkdownNoteRepository $noteRepository,
private PathTemplateGenerator $pathTemplateGenerator,
private SiteDateFormatter $dateFormatter,
```

**Why:** le nom au pluriel se lit comme la collection. Dans sept fichiers, le
même mot désignait le service **et** sa sortie à une ligne d'intervalle :

```php
private NoteSpaceRepository $spaces,                  // ligne 36
$spaces = $this->spaces->findReadableFor($user);      // ligne 234
```

Le lecteur doit tenir deux sens en tête pour suivre la méthode. Et ce n'était
pas un problème de repositories : un générateur appelé `$pathTemplates` fait
exactement la même chose. 376 noms sur 220 fichiers, relevés le 08/10/2026,
renommés d'un coup.

**How to apply:**
- La règle dit que le **rôle** est dans le nom, pas que le nom répète le type :
  `$settingRepository` et `$repository` passent tous les deux, `$settings` non.
  Quel nom choisir reste un jugement ; qu'il porte le rôle, non.
- Quand le dépôt a déjà un nom pour ce type, reprendre celui-là plutôt que d'en
  inventer un (`$settingRepository` existe 62 fois, `$auditLogger` 52 fois).
- Tenu par `tests/Unit/DependenciesAreNamedAfterTheirRoleTest.php`, qui lit les
  suffixes et les exceptions dans `tools/naming/full-word-names.json` - le même
  fichier que la règle des mots complets.

**Deux exceptions, et elles sont dans le fichier, pas dans le jugement :**
1. **Le nom dit le rôle dans la scène**, et aucune collection n'est annoncée :
   `$primary` / `$secondary` / `$source` / `$local` / `$remote` dans les tests
   de stockage, où le sujet *est* quel disque joue quel rôle. Cinq cas,
   chacun justifié dans `roleNameExceptions`.
2. **Les paramètres non promus ne sont pas contrôlés**, parce qu'un nom de
   paramètre est parfois une clé de câblage : Symfony résout
   `$contractSignatureLimiter` vers le limiteur `contract_signature`, et
   `config/services.yaml` passe une douzaine d'arguments par leur nom. Un test
   qui exigerait un renommage là casserait le conteneur au lieu d'améliorer
   un nom.

**Attention aussi :** une propriété `protected` d'un Manager ou d'un
Serializer instrumenté est un point d'extension documenté, qu'un client peut
lire dans un hook surchargé. La renommer casse ce client : ces cas passent par
le changelog.

## Anti-patterns

❌ `src/Module/Crm/assets/suite/contact_tags/` → ✅ `contact-tags/`
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
