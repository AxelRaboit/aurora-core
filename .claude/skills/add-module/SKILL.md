---
name: add-module
description: Scaffold a new Aurora module from scratch. Reads the templates under `.claude/skills/add-module/templates/`, fills placeholders, writes files, and handles the smart edits (a CORE module is generated as a self-contained Composer package — `composer.json` + `Aurora<X>Bundle` + `config/services.php` + its OWN `<X>ModuleParameterEnum` + provider — then registered in `config/bundles.php` and excluded from the central `services.yaml` glob; plus the `aliases.js` entry, context-appropriate Lucide icon, polished FR/EN translation labels). Use when the user asks to "create", "add", "scaffold", "ajouter", "créer", "générer" a new module ("nouveau module"). Stops short of entity scaffolding (defers to `/add-entity`).
scope: shared
---

# add-module

Scaffold a new Aurora module. The skill is the **only** entry point —
the previous `aurora:make:module` CLI wizard was removed to prevent
drift (devs running it bare were skipping the package wiring, the
`aliases.js` append, the icon swap, the label polish).

> **Monorepo-split convention (since 2026-05-30).** A CORE business
> module is a **self-contained Composer package** : `axelraboit/aurora-<kebab>`
> with its own `composer.json`, `Aurora<X>Bundle` (extends
> `AbstractAuroraModuleBundle`), `config/services.php`, and — crucially —
> its **own** `<X>ModuleParameterEnum` + `<X>ModuleParameterProvider`. The
> central `ModuleParameterEnum` (`src/Module/Configuration/Setting/Enum/`) is
> now **core-infra only** (General/Platform/Configuration/Media/Ged) and
> **must not** receive new business-module cases. Reference modules to mirror :
> `src/Module/Notes/` (sub-toggles) and `src/Module/Tools/` (leaf). This is
> what makes a module à-la-carte installable (`make split-module`).

The shape of every generated file lives in
`.claude/skills/add-module/templates/*.tpl`. The skill reads each
template, applies the placeholder substitution, and writes the result
via the `Write` tool. **Don't reimplement the file contents** — read
the template, substitute, write.

> Conventions doc canonique: `docs/aurora-core/dev/add_module.md` (core)
> ou `docs/aurora-client/extending/add_module.md` (client).
> Pour les sub-features d'un module existant : `/add-submodule`.

## Step 0 — Detect context (CORE vs CLIENT)

```bash
grep '"name":' composer.json | head -1
#   → "axelraboit/aurora"        → CORE
#   → anything else (and require lists `axelraboit/aurora`) → CLIENT
```

If unclear, **stop and ask**. The context decides namespace prefix,
sequence prefix, asset path, and which `Module.*.php.tpl` /
`Context.*.php.tpl` / `FrontendDescriptor.*.php.tpl` variant to read.

| | CORE | CLIENT |
|---|---|---|
| Namespace prefix | `Aurora\Module\<X>` | `App\Module\<X>` |
| Sequence prefix (entity later) | `seq_core_<entity>_id` | `seq_app_<entity>_id` |
| Asset path | `src/Module/<X>/assets/` | `src/Module/<X>/assets/` (same since 0.5) |
| Toggle storage | own `<X>ModuleParameterEnum::Backend` case (package-local) | `<X>Context::BACKEND_KEY` const |
| Package shape | full Composer package (composer.json + bundle + services.php + enum + provider) | lives in the client app — no package, no bundle |
| Templates root for skill reads | `vendor/axelraboit/aurora/.claude/skills/add-module/templates/` (when running from a client) OR `.claude/skills/add-module/templates/` (core) | same |

## Required inputs (ask upfront if missing)

1. **Module name** in PascalCase (`Tracking`, `Loyalty`, `WikiNotes`).
2. **Layer flags** :
   - **Toggle** (default ON) — generates `<Module>Context.php` + the
     `ModuleToggleProviderInterface` implementation on `<Module>Module`.
     Skip only for infra-only modules that must always be on
     (Dev-style).
   - **`--with-frontend`** equivalent : adds `<Module>FrontendDescriptor.php`
     (public-facing module).
   - **`--with-settings`** equivalent : adds `Setting/<Module>SettingEnum.php`
     + `Setting/<Module>ConfigurationTabProvider.php` (own admin Settings tab).
3. **NavSection priority** (default 60). Lower = higher in sidemenu. Match
   neighbour modules' priorities for visual grouping.
4. **Icon** (kebab-case Lucide name). Pick one that fits the module's
   purpose (`flame` for welding-style, `key-round` for vault,
   `clipboard-check` for workflows, `wallet` for finance, etc.) — do NOT
   default to `flame` if a better icon exists.

For CRUD entities, **do not** generate them here — defer to
`/add-entity` after the module skeleton lands.

## Step 1 — Derive the variable map

Compute these from the module name and the user's answers :

| Variable | Derivation | Example (`Loyalty`) |
|---|---|---|
| `{{MODULE}}` | PascalCase as-given | `Loyalty` |
| `{{MODULE_ID}}` | snake_case | `loyalty` |
| `{{MODULE_KEBAB}}` | kebab-case (= snake-with-dashes) | `loyalty` |
| `{{MODULE_VAR}}` | camelCase | `loyalty` |
| `{{MODULE_LABEL}}` | user-friendly label (ask if not obvious) | `Loyalty` |
| `{{ICON}}` | user input | `award` |
| `{{PRIORITY}}` | user input as string | `'60'` |
| `{{NAMESPACE}}` | `Aurora\Module\<X>` (core) or `App\Module\<X>` (client) | `Aurora\Module\Loyalty` |
| `{{MODULE_TOGGLE_LITERAL}}` | `<X>ModuleParameterEnum::Backend->value` (core) / `<X>Context::BACKEND_KEY` (client) / `null` (no-toggle) | `LoyaltyModuleParameterEnum::Backend->value` |
| `{{MODULE_TOGGLE_USE}}` | empty for CORE (the `ConfigurationTabProvider` lives in the SAME `…\Setting` namespace as the enum) / `use <NAMESPACE>\<X>Context;\n` for client | `` (empty, core) |
| `{{SERVICES_EXTRA_USE}}` | extra `use` lines for `config/services.php`, one per `--with-*` flag, else empty | see Step 2b |
| `{{SERVICES_EXTRA_INSTANCEOF}}` | extra `instanceof()->tag()` lines for `config/services.php`, else empty | see Step 2b |

> **Core toggle literal is now `->value` (a string).** The per-module enum
> (`<X>ModuleParameterEnum`) does NOT satisfy the central `ModuleParameterEnum`
> type-hint, so the `ConfigurationTab(moduleToggle: …)` arg takes the string
> key, matching the real modules (`CrmModuleParameterEnum::Backend->value`).

## Step 2 — Pick the right template variants

Module class :
- togglable + CORE → `Module.core.php.tpl`
- togglable + CLIENT → `Module.client.php.tpl`
- `--no-toggle` → `Module.NoToggle.php.tpl`

Context (only when togglable) :
- CORE → `Context.core.php.tpl`
- CLIENT → `Context.client.php.tpl`

FrontendDescriptor (only when `--with-frontend`) :
- CORE → `FrontendDescriptor.core.php.tpl`
- CLIENT → `FrontendDescriptor.client.php.tpl`

Settings (only when `--with-settings`, both core + client share these) :
- `SettingEnum.php.tpl`
- `ConfigurationTabProvider.php.tpl`

**Package shape (CORE + togglable only — the monorepo-split files)** :
- `composer.json.tpl`
- `Bundle.php.tpl`
- `services.php.tpl`
- `ModuleParameterEnum.php.tpl`
- `ModuleParameterProvider.php.tpl`

> These five are what make the module a standalone `axelraboit/aurora-<kebab>`
> package. Skip them ONLY for `--no-toggle` core-infra modules (Dev-style) that
> stay wired centrally by `AuroraBundle` — those keep their cases in the
> central `ModuleParameterEnum` and need no bundle. Client modules don't get
> these either (the client app wires its own bundles/services).

Always :
- `Controller.php.tpl`
- `index.html.twig.tpl`
- `App.vue.tpl`
- `messages.fr.yaml.tpl`
- `messages.en.yaml.tpl`

### Step 2b — `config/services.php` extra tags (per `--with-*` flag)

`services.php.tpl` always tags `ModuleInterface` (`aurora.module`) and
`ApplicationParameterProviderInterface` (`aurora.application_parameter_provider`).
Fill the two placeholders from the flags (else both empty) :

| Flag | `{{SERVICES_EXTRA_USE}}` adds | `{{SERVICES_EXTRA_INSTANCEOF}}` adds |
|---|---|---|
| `--with-settings` | `use Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface;\n` | `    $services->instanceof(ConfigurationTabProviderInterface::class)->tag('aurora.configuration_tab_provider');\n` |
| `--with-frontend` | `use Aurora\Core\Frontend\Contract\FrontendInterface;\n` | `    $services->instanceof(FrontendInterface::class)->tag('aurora.front');\n` |

Mirror the real packages : `Tools/config/services.php` (2 base tags, no
extras) vs `Notes/config/services.php` (adds the ConfigurationTab tag).

## Step 3 — Read, substitute, write

For each chosen template :

1. `Read` the file at `.claude/skills/add-module/templates/<template>.tpl`
   (from the cwd — when running in a client, the path resolves to the
   symlinked vendor copy).
2. Apply variable substitution (just `str_replace` style — every
   `{{KEY}}` token is mechanical).
3. `Write` the result to the target path :

| Template | Target |
|---|---|
| `Module.{core,client,NoToggle}.php.tpl` | `src/Module/<Module>/<Module>Module.php` |
| `Context.{core,client}.php.tpl` | `src/Module/<Module>/<Module>Context.php` |
| `FrontendDescriptor.{core,client}.php.tpl` | `src/Module/<Module>/<Module>FrontendDescriptor.php` |
| `SettingEnum.php.tpl` | `src/Module/<Module>/Setting/<Module>SettingEnum.php` |
| `ConfigurationTabProvider.php.tpl` | `src/Module/<Module>/Setting/<Module>ConfigurationTabProvider.php` |
| `Controller.php.tpl` | `src/Module/<Module>/Controller/Backend/<Module>Controller.php` |
| `index.html.twig.tpl` | `src/Module/<Module>/templates/backend/index.html.twig` |
| `App.vue.tpl` | `src/Module/<Module>/assets/backend/<Module>App.vue` |
| `messages.fr.yaml.tpl` | `src/Module/<Module>/translations/messages.fr.yaml` |
| `messages.en.yaml.tpl` | `src/Module/<Module>/translations/messages.en.yaml` |
| `composer.json.tpl` *(core togglable)* | `src/Module/<Module>/composer.json` |
| `Bundle.php.tpl` *(core togglable)* | `src/Module/<Module>/Aurora<Module>Bundle.php` |
| `services.php.tpl` *(core togglable)* | `src/Module/<Module>/config/services.php` |
| `ModuleParameterEnum.php.tpl` *(core togglable)* | `src/Module/<Module>/Setting/<Module>ModuleParameterEnum.php` |
| `ModuleParameterProvider.php.tpl` *(core togglable)* | `src/Module/<Module>/Setting/<Module>ModuleParameterProvider.php` |

> The `Aurora<Module>Bundle.php` lives at the module **root** (not in a
> sub-dir) — `AbstractAuroraModuleBundle::moduleDir()` derives the module path
> from the bundle file's location.

## Step 4 — The smart post-edits (Claude-only work)

These are NOT templates — they're context-sensitive edits to existing
files. The previous CLI wizard merely printed hints about them; that's
exactly the gap this skill closes.

### 4a. Register the package bundle + exclude from the central glob (CORE + togglable)

The toggle definitions are NO LONGER a patch to the central
`ModuleParameterEnum` — they live in the generated
`<Module>ModuleParameterEnum` (Step 3). Two central files still need a
one-line edit so the package is wired:

**1. `config/bundles.php`** — register the bundle (alongside the other
`Aurora<X>Bundle` lines, alphabetical) :

```php
Aurora\Module\<Module>\Aurora<Module>Bundle::class => ['all' => true],
```

**2. `config/services.yaml`** — exclude the module dir from the central
`Aurora\: resource` glob (it now self-registers via its own
`config/services.php`), in the `exclude:` list :

```yaml
- '../src/Module/<Module>/'
```

> Without the exclusion you get a double-registration; without the bundle
> line the module contributes nothing (Doctrine mappings, Twig namespace,
> translations and `resolve_target_entities` all come from the bundle).

If `--with-frontend` was used, add a `Frontend` case to the generated
`<Module>ModuleParameterEnum` (mirror `src/Module/Photo/Setting/PhotoModuleParameterEnum.php`):
a `case Frontend = 'modules_<module_id>_frontend';` plus its `getLabel()` /
`getDescription()` arms and a `getCascadeRequires()` arm pointing at
`self::Backend->value`.

Run afterwards:

```bash
make sf CMD="aurora:application-parameter"
```

…which seeds the new toggle row(s) in `core_settings` (default `'1'` = ON)
via the generated `<Module>ModuleParameterProvider`.

### 4b. Append to `aliases.js` (CORE only)

File: `aliases.js` at the repo root. Add `@<module-kebab>` in
alphabetical order :

```js
"@<kebab>": moduleAlias("<Module>"),
```

### 4c. Polish the FR/EN labels

The template fills `messages.{fr,en}.yaml` with `{{MODULE_LABEL}}` plus
short placeholders. Open both files and write proper sentences for :
- `backend.modules.<module_id>` — module display name
- `backend.modules.<module_id>_description` — one-line description shown
  on `/dev/dashboard/modules` and `/backend/settings`
- `backend.nav.<module_id>` — sidemenu label
- `backend.nav.<module_id>_description` — sidemenu tooltip
- `<module_id>.title` — page H1 in the Vue entrypoint

Action-oriented French, neutral English. Don't leave the literal
`{{MODULE_LABEL}}` if the substitution missed something.

### 4d. Client-only manual wiring

CLIENT projects don't inherit aurora-core's auto-discovery globs. Verify
(and edit if missing) :

1. `config/services.yaml` — `_instanceof: ModuleInterface: tags: [aurora.module]`
   must already exist. If not, ask the user to add it.
2. `config/packages/twig.yaml` — append :
   ```yaml
   twig:
       paths:
           '%kernel.project_dir%/src/Module/<Module>/templates': '<Module>'
   ```
3. `config/services.yaml` — append the new translations dir to
   `DumpJsTranslationsCommand.$extraSourceDirs`.

## Step 5 — Sync + verify

```bash
make module-sync    # privileges:sync + menus:sync + application-parameter + translation + build
make ft             # tests + lint
```

If the user mentioned an entity (e.g. "create the Loyalty module with a
Reward entity"), chain to `/add-entity` once `make ft` is green.

## Boundaries

- **Read-only on entities** — never scaffold `<Name>.php` here. Defer
  to `/add-entity` (it asks for field types).
- **One module per invocation.** If the user wants two modules, ask
  which to start with.
- **Don't reimplement the templates.** Every file is read from a `.tpl`
  in `.claude/skills/add-module/templates/`. If a convention needs to
  change (new field, new method, new attribute), edit the template, not
  the skill.
- **Don't generate toggles for sub-features.** Add them later via
  `/add-submodule` (it cascades the parent toggle key correctly).
- **Always summarise** at the end: list every file created and every
  file edited, with line ranges for the edits (so the user can review).
- **Never `--no-verify`** or skip hooks.
- **Apply the doc-audit convention** (cf.
  `process_doc_audit_before_commit.md`) : if the new module touches a
  documented topic, audit related docs/memories before committing.
