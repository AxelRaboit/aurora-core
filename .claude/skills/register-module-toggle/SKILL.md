---
name: register-module-toggle
description: Retro-fit an existing Aurora module so its toggle (and its sub-module toggles) appear on /dev/dashboard/modules and gate the nav at runtime. Use when the user asks "why does my module not show up in /dev/dashboard/modules", "the module is missing from the modules dashboard", "register Notes in the toggle dashboard", "expose toggles for <Module>", or when a module was scaffolded without ModuleToggleProviderInterface. Wires cases in the central ModuleParameterEnum, a <Module>Context, and the toggle provider on the module class. Idempotent.
scope: shared
---

# register-module-toggle

Wire an existing module so its toggles appear on **`/dev/dashboard/modules`**
and gate the live nav.

This targets a module that already exists in `src/Module/<Module>/` but is
**missing from the dashboard**, typically because it was scaffolded without
`ModuleToggleProviderInterface` and without a `<Module>Context`.

> **For a brand-new module**, use `/add-module` - it wires the toggles as part
> of the scaffolding and this skill has nothing left to do.
> **For one sub-module under an already-registered parent**, use
> `/add-submodule`.

## The one thing to know before starting

**There is exactly one toggle enum**, and every module's toggles live in it:

```
src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php
```

One provider exposes it, `CoreModuleParameterProvider`, which does nothing but
`yield from ModuleParameterEnum::cases()`. There is no
`<Module>ModuleParameterEnum`, no `<Module>ModuleParameterProvider`, and no
`src/Module/<Module>/Setting/` folder holding either - check with
`find src/Module -name '*ModuleParameterEnum.php'` before believing otherwise.

Per-module enums belonged to the split into `aurora-*` packages, abandoned in
August 2026. This skill described them until 2026-09-16 and was describing a
world that no longer exists: following it produced a class nothing reads, and
toggles that never reached the dashboard.

## Required inputs (ask upfront if missing)

1. **Module** (PascalCase) - must exist: `src/Module/<Module>/<Module>Module.php`.
   If absent, stop and point at `/add-module`.
2. **The list of sub-modules to register.** Read the module's
   `getNavSections()`: each `NavItem` is a candidate. Confirm the list and the
   labels with the user, and ask which ones are worth a toggle - an item
   nobody would ever turn off alone is noise on the dashboard.
3. **Cascade dependencies between the sub-modules.** Not always the module's
   own backend toggle: `StudioContracts` requires `StudioCustomers`, because a
   contract screen without the client screen it hangs off is useless. Ask what
   has to be on before each sub-module can be.

A module with no sub-modules is a perfectly normal case - `Planning` has only
`PlanningBackend`. Wire the top-level toggle and stop.

## 1. The enum cases

In the central `ModuleParameterEnum`, under the comment block for the module
(add one if the module has none):

```php
// Top-level modules - backend (admin UI)
case <Module>Backend = 'modules_<module_id>_backend';

// Sub-modules - <Module>
case <Module><Sub1> = 'modules_<module_id>_<sub1_id>';
```

Two naming rules, both load-bearing:

- **Case names are prefixed by their module** (`NotesMarkdown`, `GedDocuments`),
  never short. They share one namespace with every other module's cases.
- **The top-level key keeps its `_backend` suffix.** `modules_notes` would
  collide with the prefix of `modules_notes_markdown` in any key-prefix check.

Then the `match` arms:

| Method | Top-level | Sub-module |
| --- | --- | --- |
| `getLabel()` | `'backend.modules.<module_id>_backend'` | `'backend.nav.<sub_route_id>'` |
| `getDescription()` | the label key plus `_description` | the label key plus `_description` |
| `getParentCase()` | omit (falls to `default => null`) | `self::<Module>Backend` |
| `getCascadeRequires()` | omit | the key that must be on first, `->value` |
| `getModuleId()` | `'<module_id>'` | omit |

`getLabel()` and `getDescription()` are **exhaustive matches with no
`default`**. That is deliberate: adding a case without labelling it stops the
code from compiling, which is the only thing that reliably prevents a raw
translation key reaching a screen. The other three do have `default => null`.

`getCascadeDisableTargets()` derives itself from `getParentCase()` and
`getCascadeRequires()`. Nothing to write there, and nothing to keep in sync.

## 2. `<Module>Context.php`

File: `src/Module/<Module>/<Module>Context.php`. Mirror `NotesContext`:

```php
final readonly class <Module>Context
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isBackendEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::<Module>Backend);
    }

    public function is<Sub1>Enabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::<Module><Sub1>);
    }
}
```

Pass **the case**, not `->value`. `isEnabled()` accepts
`ModuleParameterEnum|string`, and every existing context passes the case.

## 3. `<Module>Module.php`

Four edits:

**a) Implement the interface.**

```php
final readonly class <Module>Module implements ModuleInterface, ModuleToggleProviderInterface
```

**b) Inject the context.** `public function __construct(private <Module>Context $<module>Context) {}`
Symfony autowires it; both classes are `final readonly` services.

**c) Gate `getNavSections()`.**

```php
public function getNavSections(): array
{
    if (!$this-><module>Context->isBackendEnabled()) {
        return [];
    }

    $items = [];

    if ($this-><module>Context->is<Sub1>Enabled()) {
        $items[] = $this-><sub1>NavItem();
    }

    if ([] === $items) {
        return [];
    }

    return [new NavSection('<module_id>', $items, priority: <existing>)];
}
```

Extract each `NavItem` into its own private method while you are here. The
same object is needed twice, once gated and once not, and duplicating the
constructor call is how the two lists drift apart.

**d) Add `getToggles()`**, one `->toToggle()` per case, top-level first. The
cascade is already encoded in the enum; nothing to wire by hand.

**Do not touch `getCatalogNavSections()`.** It returns the full list
unconditionally - it feeds the per-user module picker, which must show every
item regardless of toggle state. If the module has no catalog method yet, add
one that returns the ungated sections.

## 4. Translations

In the **module's own** `translations/messages.{fr,en}.yaml`, so a client
removing the module gets a self-contained removal:

```yaml
backend:
  modules:
    <module_id>_backend: <Display name>
    <module_id>_backend_description: <One line on what the module enables>
  nav:
    <module_id>_<sub1_id>: <Label>
    <module_id>_<sub1_id>_description: <Tooltip>
```

Sub-module keys under `backend.nav.` are usually **already there** - they were
written when the NavItem was. Check before adding; a duplicate key silently
wins or loses depending on load order.

Only the handful of core labels (`general_backend`, `platform_backend`, and
friends) live in `src/Core/Module/translations/`. A business module's labels do
not go there.

**Spanish only for what a customer reads.** The back-office falls back to
French and `CustomerFacingLocaleTest` enforces exactly that split, so module
toggle labels stay fr/en.

Two traps the suite will catch, cheaper to avoid:

- **fr and en do not order their keys the same way.** Anchoring an insertion on
  a line whose neighbours differ orphans a key into the wrong block, and
  `TranslationConsistencyTest` fails on the parity.
- **A key cannot be both a value and a block.** `status: Actif` plus `status:`
  with children is invalid YAML; name the block `statuses`.

## 5. After wiring

```bash
php bin/console aurora:application-parameter   # seeds the toggle rows
make cc                                        # new Context service
make ft
```

The command should report one created row per case you added. If the count
differs, a case is missing from `getToggles()` or from the enum.

## Runtime default

`ModuleAccessChecker` reads `SettingRepository::getBoolean($key, true)`, which
**defaults to true when the row is absent**. Nothing disappears from anyone's
screen when this skill runs: the seeding surfaces the toggles in the dashboard,
it does not change what is visible. Say so in the summary, or the user will go
looking for a change that did not happen.

## Verifying, and re-running

The skill is idempotent - re-running on a wired module should change nothing.
To check the wiring is real rather than present:

```bash
php bin/console debug:container --tag=aurora.module   # the module is a service
grep -n "<Module>" src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php
```

Then open `/dev/dashboard/modules` and turn the top-level toggle off: the whole
section must leave the menu, and the sub-toggles must grey out with it.

`/audit-module-toggles` checks the same wiring across every module at once,
and is the better tool when the question is "which modules are wrong" rather
than "fix this one".

## Boundaries

- **One module per invocation.** Two modules is two runs, two commits.
- **Never create a `<Module>ModuleParameterEnum` or a per-module parameter
  provider.** Neither has any equivalent in this repository.
- **Never create the module from scratch** - point at `/add-module`.
- **No toggle without a NavItem.** The dashboard lists nav-backed toggles; a
  pure-data sub-domain with no screen has nothing to show and nothing to gate.
- **Do not modify `getCatalogNavSections()`.**
- **Apply the doc-audit convention**: grep `docs/` and `.claude/memory/` for the
  module and correct what your change makes false, in the same commit.

## Output to the user

- The enum cases added, with their keys.
- The new `<Module>Context.php`.
- The `<Module>Module.php` edits: interface, constructor, gating, `getToggles()`.
- The translation keys added, and which ones were already there.
- The row count reported by `aurora:application-parameter`.
- The reminder that toggles default to on, so nothing changed on screen yet.
