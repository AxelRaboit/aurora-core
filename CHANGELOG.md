# Changelog Aurora-core

Format : [SemVer](https://semver.org). Section **"Dans aurora-client"** = ce que les
projets clients doivent répercuter après avoir lancé `make aurora-update`.

---

## [Unreleased]

### ⚠️ Cassant - root `templates/` éliminé (sauf `bundles/`), tout sous `src/`

Le dossier `templates/` à la racine du bundle est éliminé. Tous les templates
sont désormais co-localisés sous `src/`, en miroir du refactor `assets/` :

| Avant | Après |
|---|---|
| `templates/Module/<X>/` | `src/Module/<X>/templates/` |
| `templates/Core/` | `src/Core/templates/Core/` |
| `templates/Shared/` | `src/Core/templates/Shared/` |
| `templates/Frontend/themes/default/` | `src/Core/templates/Frontend/themes/default/` |

**Seule exception** : `templates/bundles/TwigBundle/` reste à la racine du
projet - c'est une convention Symfony hardcodée dans `FilesystemLoader` pour
les overrides de templates de bundles tiers (error pages, …). Non négociable.

**Namespaces Twig inchangés côté API** : `@Editorial`, `@Crm`, `@Platform`,
`@Core`, `@Shared` etc. continuent de résoudre vers les bons emplacements.
Aucun `render(…)` ni `include`/`extends` n'est à modifier. Les références
sans namespace (`Frontend/themes/default/layout.html.twig`) résolvent toujours
via le null namespace, qui pointe désormais à la fois sur `src/Core/templates/`
(emplacement des templates bundle) et `templates/` (encore présent pour
`bundles/TwigBundle/` + overrides client à la racine du projet).

**Côté client** : compatibilité ascendante pour les trois familles.
Pour `@<Module>`, `@Core`, `@Shared`, `AuroraBundle::prependExtension` reconnaît
deux paths d'override (le nouveau co-localisé + le legacy top-level) :

| Namespace | Nouveau path client (recommandé) | Legacy path client (backward compat) |
|---|---|---|
| `@<Module>` | `<client>/src/Module/<X>/templates/` | `<client>/templates/Module/<X>/` |
| `@Core` | `<client>/src/Core/templates/Core/` | `<client>/templates/Core/` |
| `@Shared` | `<client>/src/Core/templates/Shared/` | `<client>/templates/Shared/` |

Pour les thèmes frontend custom : `<client>/templates/Frontend/themes/<slug>/`
**reste la convention canonique** (les thèmes sont de la data côté client,
pas du code de module). `ThemeManager.countTemplates()` accepte aussi
`<client>/src/Core/templates/Frontend/themes/<slug>/` en fallback (pour le
default theme livré par Aurora en mode core dev).

Aucune migration Doctrine ; clear cache + rebuild suffit.

### ⚠️ Cassant - root `assets/` supprimé, JS/Vue co-localisé sous `src/`

Le dossier `assets/` à la racine du repo a été éliminé. Tout le JS/Vue/CSS
est désormais co-localisé sous `src/`, en miroir de la structure PHP :

| Avant | Après |
|---|---|
| `assets/Module/<X>/...` | `src/Module/<X>/assets/...` |
| `assets/Core/backend/...` | `src/Core/Frontend/backend/...` |
| `assets/Core/frontend/...` | `src/Core/Frontend/frontend/...` |
| `assets/Core/utils/...` | `src/Core/Frontend/utils/...` |
| `assets/shared/...` | `src/Core/Frontend/shared/...` |
| `assets/locales/generated/...` | `src/Core/Frontend/locales/generated/...` |
| `assets/css/...` (sauf modules) | `src/Core/Frontend/css/...` |
| `assets/css/modules/notes/markdown/preview.css` | `src/Module/Notes/assets/backend/markdown/components/preview.css` |
| `assets/css/modules/editorial/prose.css` | `src/Module/Editorial/assets/backend/posts/prose.css` |
| `assets/css/core/sidemenu.css` | `src/Core/Frontend/backend/sidemenu/sidemenu.css` |
| `assets/controllers/` | `src/Core/Frontend/stimulus/` (renommé pour éviter le clash avec `Controller/` PHP) |
| `assets/controllers.json` | `src/Core/Frontend/stimulus.json` (override Symfony : `config/packages/stimulus.yaml`) |
| `assets/tests/` | `src/Core/Frontend/tests/` |
| `assets/.client-fallback/` | `src/Core/Frontend/.client-fallback/` |
| `assets/{app,flash,theme,guest,i18n,stimulus_bootstrap}.js` | `src/Core/Frontend/{app,flash,theme,guest,i18n,stimulus_bootstrap}.js` |

**Aliases Vite inchangés côté API** : `@vault`, `@editorial`, `@platform`,
`@configuration`, `@media`, `@general`, `@dev` etc. pointent toujours vers
les bons emplacements (`src/Module/<X>/assets/`). `@core`, `@`, `@shared`
résolvent sous `src/Core/Frontend/`. Les imports `@/css/...` ont été
remplacés par des chemins relatifs (`./preview.css`, `./sidemenu.css`)
car les CSS sont désormais co-localisés avec leur SFC.

**Stimulus** : le folder a été renommé pour éviter la confusion avec les
controllers PHP. La convention par défaut Symfony (`assets/controllers/`)
est overridée via `config/packages/stimulus.yaml`.

**Aucune migration Doctrine** ; côté front, rebuild Vite suffit.

Voir [`MIGRATION_0.4.md`](docs/aurora-client/MIGRATION_0.4.md) pour la note
détaillée côté client (rien ne change pour `aurora-client/assets/client/`).

### ⚠️ Cassant - namespaces Core déplacés sous leur module parent

Alignement de `src/Core/` sur la convention Vault-style déjà en place
côté `src/Module/` : les sous-modules Core vivent désormais dans un
sous-dossier de leur module parent (`Aurora\Core\Platform\User`,
`Aurora\Core\Configuration\Setting`, etc.). Voir
[`MIGRATION_0.4.md`](docs/aurora-client/MIGRATION_0.4.md) pour la table
de correspondance + le `sed` bulk.

| Avant | Après |
|---|---|
| `Aurora\Core\Dashboard\*` | `Aurora\Core\General\Dashboard\*` |
| `Aurora\Core\Profile\*` | `Aurora\Core\General\Profile\*` |
| `Aurora\Core\Search\*` | `Aurora\Core\General\Search\*` |
| `Aurora\Core\Audit\*` | `Aurora\Core\Dev\Audit\*` |
| `Aurora\Core\Setting\*` | `Aurora\Core\Configuration\Setting\*` |
| `Aurora\Core\Theme\*` | `Aurora\Core\Configuration\Theme\*` |
| `Aurora\Core\Media\*` | `Aurora\Core\Media\Library\*` |
| `Aurora\Core\User\*` | `Aurora\Core\Platform\User\*` |
| `Aurora\Core\Agency\*` | `Aurora\Core\Platform\Agency\*` |
| `Aurora\Core\Auth\*` | `Aurora\Core\Platform\Auth\*` |
| `Aurora\Core\Service\{Entity,Dto,Manager,Repository,Serializer,Controller,View}\*` | `Aurora\Core\Platform\Service\{...}\*` |
| `Aurora\Core\Service\{Platform,Media,Configuration,General}Context` | `Aurora\Core\{Platform,Media,Configuration,General}\{Same}Context` (racine du folder du module) |
| `Aurora\Module\<X>\Service\<X>Context` (12 business modules) | `Aurora\Module\<X>\<X>Context` (racine du folder du module) |
| `Aurora\Core\Menu\*` | `Aurora\Module\Editorial\Menu\*` (Menu = sous-module d'Editorial) |
| `Aurora\Core\MountPoint\*` | `Aurora\Module\Dev\MountPoint\*` |
| `Aurora\Core\Platform\*` | `Aurora\Module\Platform\*` (promotion Core → Module) |
| `Aurora\Core\Configuration\*` | `Aurora\Module\Configuration\*` |
| `Aurora\Core\Media\*` | `Aurora\Module\Media\*` |
| `Aurora\Core\General\*` | `Aurora\Module\General\*` |
| `Aurora\Core\Dev\*` | `Aurora\Module\Dev\*` |
| `Aurora\Core\{Platform,Configuration,Media,General,Dev}Module` | `Aurora\Module\<X>\<X>Module` |

**2e vague - templates + assets** : `templates/Core/backend/<X>/` et
`assets/Core/backend/<X>/` ont aussi été déplacés vers les modules promus
(`templates/Module/<NewModule>/backend/<X>/` et idem assets). 5 nouveaux
aliases Vite : `@platform`, `@configuration`, `@media`, `@general`, `@dev`.
Voir [MIGRATION_0.4.md](docs/aurora-client/MIGRATION_0.4.md) pour le sed bulk
côté client.

**Convention unique** : tout module (avec une entrée dans la sidemenu) vit
sous `src/Module/`. `src/Core/` ne contient plus **que** de l'infrastructure
cross-cutting (Encryption, Frontend, Locale, Mail, Notification, Module/Contract,
Repository, Scheduler, Sequence, Storage, Support, Twig, Validation, etc.).
Plus aucun `<X>Module.php` à la racine de `src/Core/`.

**Inchangé** (cross-cutting infra) : `Encryption`, `Frontend`, `Locale`,
`Mail`, `Menu`, `Migration`, `Module`, `MountPoint`, `Notification`,
`Repository`, `Scheduler`, `Sequence`, `Storage`, `Support`,
`Timestampable`, `Twig`, `Validation`.

**Aucune migration Doctrine** - les tables (`core_user`, `core_agency`,
`core_audit_log`, `core_media`, `core_setting`, etc.) gardent leur nom.

### ⚠️ Cassant - CLI wizards `aurora:make:module` + `aurora:make:entity` supprimés

Les deux commandes Symfony ajoutées plus tôt dans Unreleased ont été
**retirées**. Tout scaffolding passe désormais par les skills Claude
`/add-module` et `/add-entity`. Motivation : un dev pressé pouvait
exécuter le wizard CLI directement (`bin/console aurora:make:module
Loyalty`) et zapper les edits post-scaffold qui ne peuvent pas être
mécaniques :

- Patch sur `ModuleParameterEnum` (5 match arms à étendre côté core)
- Append sur `aliases.js` (côté core)
- Choix d'une icône Lucide pertinente (au lieu de `'flame'` par défaut)
- Polish des labels FR/EN (au lieu de `{{MODULE_LABEL}}`)
- Fleshing-out des fields sur `Abstract<Name>` (`make:entity`)

Le wizard CLI imprimait des hints textuels pour ces étapes - facilement
ignorés. Le skill Claude les exécute systématiquement, donc on supprime
l'entrée CLI pour fermer la porte aux dérives.

**Source de vérité unique** : les templates `.tpl` ont juste été
déplacés depuis `src/Core/Module/Command/templates/` vers
`.claude/skills/add-module/templates/` et `.claude/skills/add-entity/templates/`.
Le skill lit les `.tpl` via `Read`, substitue les `{{KEY}}` tokens, et
écrit le résultat via `Write` - aucune duplication.

**Migration** : si vous avez un script CI qui appelait
`bin/console aurora:make:*`, remplacez par une invocation Claude (par
ex. dans un agent CI), ou déclenchez le skill via le harness Claude
Code en mode batch.

### ⚠️ Cassant - `ApplicationParameterEnumInterface::getPlaceholder(): ?string`

Nouvelle méthode obligatoire sur l'interface. Tous les enums clients
implémentant `ApplicationParameterEnumInterface` (settings module) doivent
ajouter une implémentation par défaut :

```php
public function getPlaceholder(): ?string
{
    return null;
}
```

Override par case quand un exemple concret est plus parlant que la
description (`'INV-2026-000042'` pour un préfixe, `'admin@example.com'`
pour un email). Les 13 enums core ont déjà été migrés en interne.

Comportement runtime : si `getPlaceholder()` renvoie `null` ET que le
`defaultValue` du setting est non-trivial (non-vide, non-`'0'`),
`SettingsViewBuilder` utilise le défaut comme placeholder. Couvre la
mer de préfixes (`'INV'`, `'DEAL'`, `'ORD'`, …) et les défauts
Notes/Assistant (`'qwen3:8b'`, `'2048'`, …) sans wirage par-case.

### Dans aurora-client

Lancer après `make aurora-update` :

```bash
# 1. Déplacer les dossiers d'extension (Agency, User, …) sous Core/Platform/
git mv src/Module/Core/Agency src/Module/Core/Platform/Agency

# 2. Renommer les namespaces (sed bulk - voir MIGRATION_0.4.md pour la commande complète)
grep -rl 'Aurora\\Core\\Agency\\' src tests config | xargs sed -i 's|Aurora\\Core\\Agency\\|Aurora\\Core\\Platform\\Agency\\|g'

# 3. Ajouter getPlaceholder() sur les enums clients implémentant
#    ApplicationParameterEnumInterface (au minimum un `return null;`)
grep -rl "implements ApplicationParameterEnumInterface" src | xargs -I{} echo "Patch {} - add getPlaceholder(): ?string { return null; }"

# 4. (Optionnel) Câbler `placeholderKey: $case->getPlaceholder()` sur les
#    ConfigurationTabProvider clients pour forwarder les placeholders au
#    SettingFieldDescriptor.

# 5. Re-générer + valider
composer dump-autoload && make cc && make ft
```

Côté welding : `WeldingSettingEnum::getPlaceholder()` câblé avec 7 vrais
placeholders (`'WLD'`, `'WPDF'`, `'inspecteur@example.com'`, …) et
`WeldingModuleParameterEnum::getPlaceholder() => null` puisque les
toggles modules rendent en switch (pas d'input).

### Ajouté

#### Settings
- `ConfigurationTab::$moduleToggle` (`ModuleParameterEnum|string|null`) -
  cache l'onglet de `/backend/settings` quand le module est désactivé
  dans `/dev/dashboard/modules`. 5 tab providers core déjà câblés (Crm,
  Ecommerce, Notes, PersonalFinance, Assistant).
- `SettingFieldDescriptor::$placeholderKey` (`?string`) - clé i18n
  optionnelle pour le placeholder de l'input. `SettingsViewBuilder`
  traduit + transmet dans le payload Vue ; `SettingsApp.vue` consomme
  via `parameter.placeholder`. Si null + type `text`/`int`/`textarea`,
  fallback automatique sur `defaultValue` (couvre les ~20 préfixes
  sequences sans wirage par-case).
- 13 templates `.tpl` pour le scaffold entity 5 couches Sylius
  (Entity triplet + DTO quartet + Manager pair + Serializer pair +
  Repository + Controller) sous `.claude/skills/add-entity/templates/`.
  Le skill `/add-entity` les lit + applique les substitutions + patche
  `AuroraBundle::$resolve_target_entities` + flesh-out des fields.

#### Skills Claude
- `/audit-module-toggles` - audit read-only de tous les modules contre
  la convention toggle (20 critères : enum case, getToggles(), Context
  isBackendEnabled, NavSection gating, getCatalogNavSections unfiltered,
  sous-toggles, translations, ConfigurationTab.moduleToggle). Allowlist
  d'infra (Configuration / Platform / Dev / Media / General).
- Skills `/add-module` et `/add-entity` lisent désormais les templates
  `.tpl` co-localisés sous `.claude/skills/<skill>/templates/` et
  scaffoldent les fichiers directement (Read + substitution + Write),
  puis font les edits délicats (patch `ModuleParameterEnum`,
  `aliases.js`, fleshing-out AbstractX). Plus de CLI wizard
  intermédiaire - un seul point d'entrée, zéro risque de dérive.

#### Templates wizard
- `src/Core/Module/Command/templates/entity/*.tpl` (13 fichiers) - le
  pattern 5 couches Sylius vit là, plus en markdown dans le skill.
- `SettingEnum.php.tpl` et `ConfigurationTabProvider.php.tpl` du wizard
  `make:module` câblent désormais `getPlaceholder() => null` et
  `placeholderKey: $case->getPlaceholder()` + `moduleToggle:`
  context-aware (core enum case / client `BACKEND_KEY` / `null`).

### Changé
- `extend-aurora-entity` skill : namespaces mis à jour
  (`Aurora\Module\Platform\Agency`, plus l'ancien `Aurora\Core\Agency`)
  + asset paths post-0.5 (`src/Module/<X>/assets/backend/`) + alias
  Vite par-module (`@platform/...` au lieu de `@aurora/Core/...`).
- `add-submodule` skill : asset paths post-0.5 alignés CORE+CLIENT.
- `check-extensibility` skill : check 17b (User-style `applyInput`
  absence légitime) + check 26 (audit des toggles de sous-modules).
- `make aurora-update` (Makefile distribué via `sync-makefile`) :
  enchaîne désormais `make translation && make build` à la fin pour
  régénérer le bundle Vite avec les i18n du nouveau core - plus de
  clés `backend.foo.bar` brutes affichées après bump.
- Commentaires aurora-core nettoyés de tous les exemples
  welding-internes (`WLD`, `modules_welding_backend`, `WeldingFoo`,
  etc.) - welding vit en client depuis 05e374ec, les exemples
  utilisent maintenant des valeurs neutres (`INV`,
  `modules_<module_id>_backend`, `MyEntity`).

---

## [0.3.0] - 2026-05-17

### Ajouté
- **Module Assistant IA** (Phase 1A + 1B) : chat synchrone avec un LLM local Ollama
  (qwen3:8b par défaut), tool-calling (`aurora_search`, `filesystem_read`,
  `filesystem_write`, `filesystem_search`, `image_read` via qwen2.5vl),
  mount-points configurables par utilisateur, flow de confirmation pour les
  actions destructives (write).
- **Onglet "Assistant" dans /backend/settings** : modèle chat, modèle vision,
  timeout HTTP, num_ctx, prompt système - tunables sans redéploiement (lecture DB
  avec fallback env).
- **`make sync-env`** + `bin/sync-client-env` : détecte les blocs
  `###> aurora/* ###` manquants dans `.env` et les insère au-dessus du divider
  CLIENT CUSTOM. Idempotent, valeurs existantes jamais touchées.
- **Divider `# === CLIENT CUSTOM ===`** dans `.env` aurora-client : sépare
  explicitement la zone gérée par aurora-core de la zone propriété du client.
- **`make sync-makefile` refusé** si Makefile a des edits non commités
  (`FORCE=1` pour forcer).
- **Tests** : +291 tests sur la période, total 2694.
- **`docs/aurora-shared/`** : nouveau dossier de docs transversales (form_validation,
  testing_php/vue, translations, scheduler, convention_seo_head) partagé entre
  aurora-core et aurora-client via vendor.
- **`docs/aurora-client/deployment/`** : guide principal + worker_systemd +
  apache_xsendfile + ocr_setup regroupés ici.
- **`docs/aurora-core/ops/prerequisites.md`** : checklist exhaustive des prérequis
  système, PHP, Ollama, vars d'env.

### Changé
- `Makefile` client : `README.md` n'est plus symlinké depuis le vendor - copié une
  seule fois à l'init, ensuite propriété du client.
- Docs : plus de symlinks `docs/aurora-*/` côté client - lecture directe dans
  `vendor/axelraboit/aurora/docs/`.
- Notes settings (Markdown + Block) : labels disambiguïsés
  ("Notes Markdown - Taille max…" vs "Notes Block - …").

### Dans aurora-client - à faire après `make aurora-update`

| Action | Commande / fichier |
|--------|-------------------|
| Ajouter les vars d'env `ASSISTANT_*` et `OCR_*` si absentes | `make sync-env` les ajoute automatiquement |
| Vérifier que `README.md` est bien un vrai fichier (plus un symlink) | `ls -la README.md` - si symlink, `make sync-claude-md` le remplace par une copie |
| Parcourir la section "CLIENT CUSTOM" de `.env` | `make sync-env` a ajouté le divider |

### Breaking changes
- Aucun changement d'API publique.

---

## [0.2.0] - 2026-05 (antérieur à ce changelog)

Établissement de la base : Posts avec éditeur bloc Editor.js, Notes Markdown
(wiki-links, graphe), Notes Block (EditorJS), Billing OCR (docTR + Ollama vision),
Galleries photo, Vault, Password Generator, extensibilité 5-couches Sylius sur 24
entités, conventions sync aurora-core → aurora-client (Makefile template, CLAUDE.md
symlink, jsconfig, security.yaml).

---

## [0.1.0] - avant 2026-05

Socle initial : Symfony 7 / PHP 8.4 / Vue 3 / Vite, modules Editorial CMS (Posts,
Taxonomies, Comments, Forms), CRM, ERP (Products), Ecommerce (Listings, Cart,
Orders), GED, HR, Planning, Project Management, auth (invitations, demandes
d'accès), thèmes, multi-langue.
