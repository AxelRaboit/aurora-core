---
name: add-submodule
description: Add a toggleable sub-feature to an existing Aurora module (e.g., add Block to Notes, add PasswordGenerator to Vault, add Webhook to Configuration). Use when the user asks to "add a sub-module", "ajouter une sous-feature", "add X to <Parent>". The sub-module gets its own folder under the parent module (Vault-style nesting since 0.4.0), its own NavItem + permission + ModuleToggle (cascaded under the parent's BACKEND_KEY), an isXEnabled() method on the parent's Context, and a Controller/Twig/Vue skeleton. Auto-detects core vs client context.
scope: shared
---

# add-submodule

Add a new **toggleable sub-feature** to an existing Aurora module. Targets
the canonical Vault-style nesting : the sub-module gets a sub-folder under
the parent module (`src/Module/<Parent>/<Sub>/` côté business modules,
`src/Core/<Parent>/<Sub>/` côté Core modules since 0.4.0 — cf.
`.claude/memory/aurora-core/architecture/decision_core_submodule_nesting.md`).

> **For a fully new module** (no existing parent), use `/add-module`.
> **For just a CRUD entity inside an existing module** (no new toggle,
> no new NavItem — just a new entity), use `/add-entity`.

## Step 0 — Detect context (CORE vs CLIENT)

Same detection as `/add-module` (composer.json check). Adapts :

| | CORE | CLIENT |
|---|---|---|
| Toggle key | new `case <Sub>` in the parent's own `<Parent>ModuleParameterEnum` (business module) — central `ModuleParameterEnum` only for core-infra parents | constant on `<Parent>Context` (`app_<parent>_<sub>`) |
| Sub-folder | `src/Core/<Parent>/<Sub>/` or `src/Module/<Parent>/<Sub>/` | `src/Module/<Parent>/<Sub>/` (assuming `<Parent>` is a client module — for extending an Aurora module, use `/extend-aurora-entity` instead) |
| Sequence prefix (if entity) | `seq_core_<sub>_id` | `seq_app_<sub>_id` |
| Asset path | `src/Module/<Parent>/assets/backend/<sub>/` or `src/Core/assets/<parent>/<sub>/` | `src/Module/<Parent>/assets/backend/<sub>/` (co-located since 0.5) |

## Required inputs (ask upfront if missing)

1. **Parent module** (PascalCase) — must exist. Verify by globbing :
   - `src/Module/<Parent>/<Parent>Module.php` (business module)
   - `src/Core/<Parent>Module.php` (core module : PlatformModule,
     ConfigurationModule, MediaModule, GeneralModule, DevModule)
   - If neither found, stop and report.
2. **Sub-module name** (PascalCase) — `Webhook`, `Block`, `Slack`,
   `PasswordGenerator`. Used as `<Sub>`. Auto-derives :
   - `<sub_id>` (snake_case)
   - `<sub-kebab>` for URL
3. **Confirm parent implements `ModuleToggleProviderInterface`** :
   ```bash
   grep -l "implements.*ModuleToggleProviderInterface" src/Module/<Parent>/<Parent>Module.php \
     src/Core/<Parent>Module.php 2>/dev/null
   ```
   If no — stop and tell the user : "Parent module doesn't implement
   `ModuleToggleProviderInterface`. Add it first (cf. `/add-module` cas 2)
   before adding togglable sub-modules."
4. **Confirm parent has a `<Parent>Context` class** :
   - Business : `src/Module/<Parent>/<Parent>Context.php` (à la racine du module)
   - Core : `src/Core/<Parent>/<Parent>Context.php` (à la racine du folder du module)
   - If absent, stop with same message as 3.
5. **Permission(s)** — single (`<parent>.<sub>.use`) or granular
   (`view`/`create`/`edit`/`delete`) ? Ask the user.
6. **Icon** for the NavItem (kebab-case Lucide). Add to `ICON_MAP` in
   `src/Core/assets/backend/sidemenu/composables/useSidemenuNav.js` if missing.
7. **Optional inputs** if the sub-module ships an entity :
   - Entity name (PascalCase)
   - Whether to scaffold the entity now (suggest `/add-entity` after)

## What gets generated

### 1. Edit `<Parent>Context.php`

Add the new toggle key + accessor.

**CLIENT** (string constant directly on Context) :

```php
// src/Module/<Parent>/<Parent>Context.php
final readonly class <Parent>Context
{
    public const string BACKEND_KEY = 'app_<parent_id>_backend';
    public const string <SUB>_KEY = 'app_<parent_id>_<sub_id>';  // ← NEW

    public function isBackendEnabled(): bool { /* existing */ }

    public function is<Sub>Enabled(): bool   // ← NEW
    {
        return $this->moduleAccessChecker->isEnabled(self::<SUB>_KEY);
    }
}
```

**CORE** — monorepo-split: add the case to the parent's **own**
`<Parent>ModuleParameterEnum`, NOT the central enum.

```php
// src/Module/<Parent>/Setting/<Parent>ModuleParameterEnum.php
case <Sub> = 'modules_<parent_id>_<sub_id>';
```

Then extend the enum's exhaustive `match ($this)` arms for the new case :
- `getLabel()` → `'backend.modules.<parent_id>_<sub_id>'` (or the reused NavItem key)
- `getDescription()` → `'…_<sub_id>_description'`
- `getCascadeRequires()` / `getDisplayParent()` : if the enum uses the generic
  ternary form (`self::Backend === $this ? null : self::Backend->value`, as in
  `NotesModuleParameterEnum`) the new sub-case cascades automatically — no
  change. If it uses a `match` (as in `PhotoModuleParameterEnum`), add a
  `self::<Sub>` arm returning `self::Backend->value`.

Then on the Context (pass `->value`) :

```php
use Aurora\Module\<Parent>\Setting\<Parent>ModuleParameterEnum;

public function is<Sub>Enabled(): bool
{
    return $this->moduleAccessChecker->isEnabled(<Parent>ModuleParameterEnum::<Sub>->value);
}
```

> **Core-infra parents only** (Configuration / Platform / Media / General /
> Dev — the modules still wired by the central `ModuleParameterEnum`) keep
> their sub-cases in `src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php`.
> Detect: if `src/Module/<Parent>/Setting/<Parent>ModuleParameterEnum.php`
> exists, use the per-module enum; otherwise the parent is core-infra.

> **No `_enabled` suffix on the key** (cf.
> `.claude/memory/aurora-core/architecture/architecture_module_parameter_enum.md`).

### 2. Edit `<Parent>Module.php`

#### a) Add the NavPermission(s)

```php
public function getPermissions(): array
{
    return [
        new NavPermission('<parent_id>.use'),                       // existing
        new NavPermission('<parent_id>.<sub_id>.use'),              // ← NEW (or granular)
    ];
}
```

#### b) Add the conditional NavItem in `getNavSections()`

```php
public function getNavSections(): array
{
    if (!$this-><parent>Context->isBackendEnabled()) {
        return [];
    }

    $items = [];

    // existing sub-modules…

    if ($this-><parent>Context->is<Sub>Enabled()) {     // ← NEW block
        $items[] = new NavItem(
            'backend_<sub_id>',
            'backend.nav.<sub_id>',
            '<icon>',
            requiredPrivilege: '<parent_id>.<sub_id>.use',
            descriptionKey: 'backend.nav.<sub_id>_description',
        );
    }

    if ([] === $items) {
        return [];
    }

    return [new NavSection('<parent_id>', $items, priority: <existing>)];
}
```

#### c) Add the same NavItem unconditionally to `getCatalogNavSections()`

(Catalog = picker UI for assigning modules per-user, shows all NavItems
regardless of toggle state.)

```php
public function getCatalogNavSections(): array
{
    return [
        new NavSection('<parent_id>', [
            // existing items…
            new NavItem('backend_<sub_id>', 'backend.nav.<sub_id>', '<icon>',
                requiredPrivilege: '<parent_id>.<sub_id>.use',
                descriptionKey: 'backend.nav.<sub_id>_description'),
        ], priority: <existing>),
    ];
}
```

#### d) Add the ModuleToggle in `getToggles()`

**CORE** — just add the enum case's `->toToggle()` (the cascade is encoded
inside the enum via `getCascadeRequires()`/`getDisplayParent()`, so nothing to
wire by hand) :

```php
public function getToggles(): array
{
    return [
        <Parent>ModuleParameterEnum::Backend->toToggle(),
        // existing sub-toggles…
        <Parent>ModuleParameterEnum::<Sub>->toToggle(),       // ← NEW
    ];
}
```

**CLIENT** — construct the `ModuleToggle` manually (no per-module enum), wiring
`parentKey` to the parent's `BACKEND_KEY` :

```php
new ModuleToggle(
    key: <Parent>Context::<SUB>_KEY,
    labelKey: 'backend.modules.<parent_id>_<sub_id>',
    descriptionKey: 'backend.modules.<parent_id>_<sub_id>_description',
    parentKey: <Parent>Context::BACKEND_KEY,                  // ← cascade : disable parent → disable sub
),
```

> **The cascade glue is `parentKey`.** When the user disables the parent
> module from the picker, all its sub-modules cascade-off automatically. Core
> encodes it in the enum's `getCascadeRequires()` (→ `self::Backend->value`);
> client passes `BACKEND_KEY` explicitly. Always wire to the parent's backend
> key (or a deeper sub-key for nested hierarchies — rare).

### 3. Scaffold the sub-module folder + files

```
src/Module/<Parent>/<Sub>/
├── Controller/Backend/<Sub>Controller.php
└── (Entity/, Dto/, Manager/, Repository/, Serializer/, View/  if CRUD — defer to /add-entity)

src/Module/<Parent>/templates/backend/<sub_id>/index.html.twig

src/Module/<Parent>/assets/backend/<sub_id>/<Sub>App.vue       # CORE + CLIENT (since 0.5)
```

For Core sub-modules under `src/Core/<Parent>/<Sub>/`, the paths use
`src/Core/<Parent>/<Sub>/...` and `src/Core/assets/<parent_lc>/<sub_lc>/...`
following the existing Core convention.

**Controller skeleton** :

```php
namespace <Ns>\Module\<Parent>\<Sub>\Controller\Backend;
// OR namespace Aurora\Core\<Parent>\<Sub>\Controller\Backend;

#[Route('/backend/<parent-kebab>/<sub-kebab>', name: 'backend_<sub_id>')]
#[IsGranted('<parent_id>.<sub_id>.use')]
final class <Sub>Controller extends AbstractController
{
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@<Parent>/backend/<sub_id>/index.html.twig');
    }
}
```

**Twig template** : standard layout-extending template (same pattern as
`/add-module` cas 1, with the crumb pointing back to the parent section).

**Vue entrypoint** : placeholder with i18n imports, user customizes.

### 4. Add translations to the parent module's `messages.{fr,en}.yaml`

```yaml
backend:
    nav:
        <sub_id>: <Sub label>
        <sub_id>_description: <Tooltip>
    modules:
        <parent_id>_<sub_id>: <Sub label for modules picker>
        <parent_id>_<sub_id>_description: <Description for modules picker>
    permissions:
        names:
            <parent_id>:
                <sub_id>:
                    use: <Permission label>

<sub_id>:
    title: <Sub page title>
```

> Keep all translations under the parent module's translations file so a
> client disabling the parent module gets a self-contained removal.

## Auto-discovery — what works without extra wiring

If the parent module is properly wired (cf. `/add-module`), the new
sub-module benefits from :
- Symfony service auto-tag (controllers auto-discovered)
- Twig namespace `@<Parent>` already mounted (new sub-template resolves
  automatically)
- Translations glob (depth 1 + 2 since 0.4.0 — cf. AuroraBundle.php)
- Vue component glob (`src/Module/*/assets/**/*.vue`) — same path
  convention CORE + CLIENT since 0.5

## Post-generation steps

```bash
# Sync DB-tracked permissions + nav from PHP into the DB
make sf CMD="aurora:privileges:sync"
make sf CMD="aurora:menus:sync"

# Re-generate frontend translation bundle
make translation

# Sync settings (seeds the new <Parent>ModuleParameterEnum case in core_settings
# — the parent's <Parent>ModuleParameterProvider already yields all its cases)
make sf CMD="aurora:application-parameter"

# Clear cache (mandatory after #[AsAlias] / DI / new toggle)
make cc

# (If you scaffolded an entity, defer to /add-entity for full 5-layer
# generation, then:)
# make migration && make migrate

# Validate
make ft
```

## Output to the user

Always end with a summary listing :
- Files **created** (with path)
- Files **edited** (with path + line numbers of changes)
- Commands to **run manually** (the post-generation block above)
- A note pointing to `/add-entity` if they need the CRUD entity layers

## Boundaries

- **One sub-module per invocation.** If the user wants 2, ask which first.
- **No entity scaffolding here.** If the sub-module ships a CRUD entity,
  generate the folder skeleton + Controller, then tell the user to invoke
  `/add-entity`.
- **Don't bypass `ModuleToggleProviderInterface`.** If the parent module
  doesn't implement it, refuse and point to `/add-module` cas 2.
- **Don't generate without a Context class.** Same reason.
- **Always cascade the new toggle under `BACKEND_KEY`** unless explicitly
  told otherwise. The cascade is what makes "disable parent → disable all
  children" work for per-user access.
- **Apply the doc-audit convention** (cf.
  `process_doc_audit_before_commit.md`) : after generating, grep
  `docs/` and `.claude/memory/` for references to the parent module that
  might need a quick mention of the new sub-feature.
