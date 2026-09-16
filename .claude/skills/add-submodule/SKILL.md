---
name: add-submodule
description: Add a toggleable sub-feature to an existing Aurora module (e.g. add Spaces to Studio, add Block to Notes, add Webhook to Configuration). Use when the user asks to "add a sub-module", "ajouter une sous-feature", "add X to <Parent>". The sub-module gets its own folder under the parent module, a case in the central ModuleParameterEnum, a NavItem, a permission, an isXEnabled() method on the parent's Context, and a Controller/Twig/Vue skeleton.
scope: shared
---

# add-submodule

Add a new **toggleable sub-feature** to an existing Aurora module. The
sub-module gets a sub-folder under its parent (`src/Module/<Parent>/<Sub>/`),
which is the nesting every module uses since 0.4.0.

> **For a fully new module** (no existing parent), use `/add-module`.
> **For just a CRUD entity inside an existing sub-domain** (no new toggle, no
> new NavItem), use `/add-entity`.

## The one thing to know before starting

**There is exactly one toggle enum**, and every module's toggles live in it:

```
src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php
```

One provider exposes it, `CoreModuleParameterProvider`. There is no
`<Module>ModuleParameterEnum` and no per-module provider anywhere in the
repository - check with `find src/Module -name '*ModuleParameterEnum.php'`
before believing otherwise. Per-module enums belonged to the split into
`aurora-*` packages, abandoned in August 2026; documentation and skills
describing them (this one included, until 2026-09-16) were describing a world
that no longer exists.

## Required inputs (ask upfront if missing)

1. **Parent module** (PascalCase) - must exist:
   `src/Module/<Parent>/<Parent>Module.php`. If absent, stop and report.
2. **Sub-module name** (PascalCase) - `Spaces`, `Webhook`, `Block`. Derives
   `<sub_id>` (snake_case) and `<sub-kebab>` (URL).
3. **Confirm the parent implements `ModuleToggleProviderInterface`** and has a
   `<Parent>Context`. Without either, stop and point at `/add-module`.
4. **Permissions** - single (`<parent_id>.<sub_id>.use`) or granular
   (`view`/`create`/`edit`/`delete`)? Ask.
5. **What the sub-module depends on.** Not always the parent's own backend
   toggle: a Studio contract needs the customer screen, so `StudioContracts`
   requires `StudioCustomers`. Ask what has to be on before this can be.
6. **Icon** (kebab-case Lucide) for the NavItem. It must also exist in
   `ICON_MAP` in `src/Core/assets/shared/nav/navMeta.js` - `navMeta.test.js`
   reads the icon names out of the PHP modules and fails on a missing one.

## 1. The enum case

In `src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php`:

```php
// Sub-modules - <Parent>
case <Parent><Sub> = 'modules_<parent_id>_<sub_id>';
```

Case names are **prefixed by their module** (`StudioSpaces`, `GedDocuments`),
not short. The value has no `_enabled` suffix.

Then four `match` arms:

- `getLabel()` → `'backend.nav.<parent_id>_<sub_id>'` (reuse the NavItem's key)
- `getDescription()` → the same key plus `_description`
- `getParentCase()` → the module's root case, e.g. `self::<Parent>Backend`
- `getCascadeRequires()` → the key that must be active first, `->value`

`getLabel()` and `getDescription()` are **exhaustive matches with no
`default`**. That is deliberate: adding a case without labelling it stops the
code from compiling, which is the only thing that reliably prevents a raw
translation key reaching a screen. `getParentCase()` and
`getCascadeRequires()` do have `default => null`.

`getCascadeDisableTargets()` derives itself from the two above - nothing to
write there.

## 2. The parent's Context

```php
public function is<Sub>Enabled(): bool
{
    return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::<Parent><Sub>);
}
```

Pass **the case**, not `->value`. `isEnabled()` accepts both, and every
existing context passes the case.

## 3. The parent's Module class

- `getPermissions()` - add the `NavPermission`(s).
- `getNavSections()` - add the NavItem inside `if ($ctx->is<Sub>Enabled())`.
  Mind the order: the menu is read top to bottom, so put the screen somebody
  opens daily above the one they fill in once.
- `getCatalogNavSections()` - add the same NavItem **unconditionally**. The
  per-user module picker needs every item regardless of toggle state.
- `getToggles()` - add `ModuleParameterEnum::<Parent><Sub>->toToggle()`. The
  cascade is already encoded in the enum; nothing to wire by hand.

## 4. The folder

```
src/Module/<Parent>/<Sub>/
├── Controller/Backend/<Sub>Controller.php
└── (Entity/ Dto/ Manager/ Repository/ Serializer/ View/ - defer to /add-entity)

src/Module/<Parent>/templates/backend/<sub-kebab>/index.html.twig
src/Module/<Parent>/<Sub>/assets/backend/<sub-kebab>/<Sub>App.vue
```

Assets are **co-located with the sub-domain** since 0.5, not under a module
root `assets/`. The Vue glob picks them up from
`src/Module/*/*/assets/**/*.vue`, and the component key drops the sub-domain
folder: `src/Module/Studio/SpaceContent/assets/backend/content/X.vue` mounts as
`studio/backend/content/X`.

Controller skeleton:

```php
#[Route('/backend/<parent-kebab>/<sub-kebab>', name: 'backend_<parent_id>_<sub_id>')]
#[IsGranted('<parent_id>.<sub_id>.view')]
class <Sub>Controller extends AbstractController
{
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        return $this->render('@<Parent>/backend/<sub-kebab>/index.html.twig');
    }
}
```

## 5. Translations

In the **parent module's** `translations/messages.{fr,en}.yaml`, so a client
disabling the parent gets a self-contained removal:

```yaml
backend:
    nav:
        <parent_id>_<sub_id>: <Label>
        <parent_id>_<sub_id>_description: <Tooltip>
    permissions:
        names:
            <parent_id>:
                <sub_id>:
                    view: <Permission label>
```

**Spanish only for what a customer reads.** `messages.es.yaml` carries the
public half; the back-office falls back to French, and
`CustomerFacingLocaleTest` enforces exactly that split.

Two traps the test suite will catch, and it is cheaper to avoid them:

- **fr and en do not order their keys the same way.** Anchoring an insertion on
  a line whose neighbours differ orphans a key into the wrong block.
  `TranslationConsistencyTest` fails on the parity.
- **A key cannot be both a value and a block.** `status: Actif` and
  `status:` with children under it is invalid YAML; name the block `statuses`.

## 6. After generating

```bash
php bin/console aurora:privileges:sync        # the new permissions
php bin/console aurora:application-parameter  # seeds the toggle row
make translation                              # the JS bundle
make cc
make ft
```

If the sub-module ships an entity, `/add-entity` does the five layers, then
`make migration && make migrate`.

**`doctrine:migrations:diff` is unusable in this repository.** The development
database still holds the tables of removed modules, so the diff proposes
dropping all of them. Generate it, keep only the statements naming your new
tables, and write the migration by hand.

## Boundaries

- **One sub-module per invocation.**
- **No entity scaffolding here** - skeleton and controller only.
- **Never create a `<Module>ModuleParameterEnum`.** It has no equivalent in
  this repository; the case goes in the central enum.
- **Don't bypass `ModuleToggleProviderInterface`** or generate without a
  Context - point at `/add-module` instead.
- **Apply the doc-audit convention**: grep `docs/` and `.claude/memory/` for
  the parent module and update what your change makes false, in the same
  commit.
