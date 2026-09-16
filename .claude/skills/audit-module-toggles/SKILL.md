---
name: audit-module-toggles
description: Audit every Aurora module against the module-toggle convention - does each one declare its cases in the central ModuleParameterEnum, implement ModuleToggleProviderInterface, gate getNavSections() on <Module>Context::isBackendEnabled(), expose getCatalogNavSections() unfiltered, register every NavItem as a sub-toggle, translate its labels in both locales, and gate its ConfigurationTab via moduleToggle? Also flags enum cases whose module no longer exists. Use when the user asks to "check", "audit", "vérifier", "valider" the toggle wiring, or "quels modules sont mal câblés ?". Read-only - reports gaps and points at /register-module-toggle.
scope: shared
---

# audit-module-toggles

Check every module under `src/Module/` against the toggle convention.
**Read-only**: report gaps, never edit. The fix-up procedure is
`/register-module-toggle`.

## The one thing to know before starting

**There is exactly one toggle enum**, and every module's toggles live in it:

```
src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php
```

One provider exposes it, `CoreModuleParameterProvider`. There is no
`<Module>ModuleParameterEnum`, no `<Module>ModuleParameterProvider`, and no
`src/Module/<Module>/Setting/` folder holding either.

This skill said the opposite until 2026-09-16 - it failed any business module
whose toggle lived in the central enum, which is every correctly wired module
in the repository. Per-module enums belonged to the `aurora-*` package split
abandoned in August 2026. **An audit run against the old text reported the
whole codebase as broken**, which is worse than not running it.

## The pieces

- `ModuleParameterEnum` - one case per toggle, carrying labels, descriptions,
  the cascade graph and `getModuleId()`.
- `ModuleToggleProviderInterface` - `getToggles(): list<ModuleToggle>` on the
  module class.
- `ModuleToggleRegistry` - collects from every service implementing that
  interface and renders `/dev/dashboard/modules`.
- `<Module>Context` - one `is<X>Enabled()` per case, wrapping
  `ModuleAccessChecker`.
- `ConfigurationTab::$moduleToggle` - `ModuleParameterEnum|string|null`, hides
  a settings tab when its module is off.

## Inputs

- **Module name** (optional, PascalCase). If given, audit only that one.
  Otherwise walk every `src/Module/*/[A-Z]*Module.php`.
- In a client project, also walk `App\Module\` modules. Same shape, different
  namespace.

## Modules with no toggle

Two modules legitimately have none, and **each says why in its own class
docblock**:

- `Dev` - gated by `ROLE_DEV` at the NavItem level; no client should be able to
  switch off Aurora's own dev panel.
- `Documentation` - whoever may open the back office may read how it works.

That docblock is the audit rule, not a hard-coded allowlist: **a module without
a toggle passes only if it explains itself in prose.** A silent absence is a
gap, because it cannot be told apart from an oversight. Report it as
`⏭️ no toggle (documented)` or `❌ no toggle, no reason given`.

Do not carry an allowlist of "always-on infra" from memory. `Configuration`,
`Platform` and `General` all have real toggles today, and `Media` is not a
module any more.

## Checks per module

### 1. Module class

1. `src/Module/<Module>/<Module>Module.php` exists.
2. It `implements ModuleToggleProviderInterface`.
3. It injects `<Module>Context` in the constructor.

### 2. Enum cases

4. A top-level case `<Module>Backend = 'modules_<module_id>_backend'` exists in
   the central enum. The `_backend` suffix is mandatory: `modules_notes` is a
   prefix of `modules_notes_markdown` and collides in any key-prefix check.
5. Case **names are prefixed by their module** (`NotesMarkdown`, not
   `Markdown`). They share one namespace with every other module's cases.
6. `getLabel()` and `getDescription()` have an arm for every case, with **no
   `default`**. A `default` arm added to either is a finding on its own: the
   exhaustive match is what stops a raw translation key reaching a screen.
7. `getModuleId()` returns `'<module_id>'` for the top-level case and nothing
   for the sub-cases.
8. Each sub-case has `getParentCase() => self::<Module>Backend` and a
   `getCascadeRequires()` naming what must be on first. The two are usually the
   same key but need not be: `StudioContracts` displays under `StudioBackend`
   and requires `StudioCustomers`.
9. `getCascadeDisableTargets()` is derived from the two above. If a module
   hand-writes cascade targets anywhere, that is a finding.

### 3. Context and nav gating

10. `src/Module/<Module>/<Module>Context.php` exists with `isBackendEnabled()`.
11. Each method passes **the enum case**, not `->value` and not a hardcoded
    `true`. The string form still type-checks, so grep for it rather than
    trusting the signature.
12. `getNavSections()` short-circuits on `!isBackendEnabled()`.
13. Each `NavItem` in `getNavSections()` sits behind its own sub-toggle check.
14. `getCatalogNavSections()` returns the **full** list, ungated. The catalog
    feeds the per-user module picker, which must show every item whatever the
    toggles say.
15. Both lists build their `NavItem`s from the same private method. Two
    literal constructor calls for one item is how the lists drift apart, and
    it is the most common real defect here.

### 4. `getToggles()`

16. Returns one `ModuleParameterEnum::<Case>->toToggle()` per case the module
    owns. **Cross-check the count** against the cases found in step 4-5: a case
    in the enum that no `getToggles()` returns never reaches the dashboard.

### 5. Translations

17. `backend.modules.<module_id>_backend` and its `_description` exist in the
    module's own `translations/messages.fr.yaml` **and** `messages.en.yaml`.
    Only core labels (`general_backend`, `platform_backend`) live in
    `src/Core/Module/translations/`.
18. Every sub-case has `backend.nav.<sub_route_id>` and its `_description` in
    both locales. These usually predate the toggle - they were written for the
    NavItem.
19. Not in Spanish. The back-office falls back to French by design, and
    `CustomerFacingLocaleTest` enforces the split.

### 6. Settings tab

20. If the module ships a `ConfigurationTabProvider`, its own tabs declare
    `moduleToggle:` with the case or its key. Only a missing or `null` value on
    a module-owned tab is a finding.
21. A cross-module tab contributed by the module (the shared `sequences` tab is
    the standing example) must leave `moduleToggle: null`.

### 7. Cases without a module

Walk the enum in the other direction: **every case must belong to a module that
still exists.** A module removed from `src/Module/` leaves its cases behind,
`CoreModuleParameterProvider` keeps seeding them into `core_settings` on every
install, and clients carry rows for a feature they will never see.

This check is why the skill exists at all, and it is the one that was missing:
`MediaBackend` and `MediaLibrary` outlived their module by four months without
anything noticing.

```bash
grep -oE "case ([A-Za-z]+) = 'modules_([a-z_]+)'" src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php
ls src/Module/
```

## Output

One table, every module, one row:

```
| Module        | Enum | Context | Nav gate | getToggles | Trans | Settings tab |
|---------------|------|---------|----------|------------|-------|--------------|
| Studio        | ✅   | ✅      | ✅       | ✅         | ✅    | ⏭️ no tab    |
| Editorial     | ✅   | ✅      | ⚠️ partial | ✅       | ✅    | ✅           |
| Documentation | ⏭️ no toggle (documented) |
```

Then a separate short list for **orphan cases** - cases whose module is gone -
because they belong to no row in the table and are the finding most likely to
be real.

Below the table, each `❌` and `⚠️` with the exact file and line (or "missing
file") and the one-line fix or the `/register-module-toggle` invocation.

Finish with one sentence: `✅ All N modules pass.` or `⚠️ N modules need
attention - run /register-module-toggle on …`.

## Boundaries

- **Read-only.** No `Edit`, no `Write`. Point at `/register-module-toggle
  <Module>` or `/add-submodule <Module> <Sub>`.
- **Don't query the database.** Whether the rows are seeded is
  `aurora:application-parameter`'s business; this audit is about source
  conformance. The one exception is check 7, where the point is precisely that
  the rows outlive the code - and even there, read `src/Module/`, not the DB.
- **Never propose a `<Module>ModuleParameterEnum`** as the fix for anything.
- **Count the sub-toggles.** `N` NavItems in the catalog against `M` sub-cases
  in the enum: the difference is the commonest scaffolding gap.
