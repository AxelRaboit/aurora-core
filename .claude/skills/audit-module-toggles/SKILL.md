---
name: audit-module-toggles
description: Audit every Aurora module against the module-toggle convention — does each one declare its OWN `<Module>ModuleParameterEnum` + `<Module>ModuleParameterProvider`, implement `ModuleToggleProviderInterface`, gate `getNavSections()` on `<Module>Context::isBackendEnabled()`, expose `getCatalogNavSections()` unfiltered, register every NavItem as a sub-toggle, and gate its `ConfigurationTab` via `moduleToggle:`? Use when the user asks to "check", "audit", "vérifier", "valider" the toggle wiring; or to find which modules are missing from `/dev/dashboard/modules` ("quels modules sont mal câblés ?", "tous les modules ont-ils les toggles ?"). Read-only — reports gaps and points at `/register-module-toggle` for the fix.
scope: shared
---

# audit-module-toggles

Mechanically check every module under `src/Module/` (or `src/Core/`) for the
module-toggle convention introduced by:

- `Aurora\Core\Module\Contract\ModuleToggleProviderInterface` — declares
  `getToggles(): list<ModuleToggle>` on the module class.
- `Aurora\Core\Module\Toggle\ModuleToggle` — value object the registry
  collects to render `/dev/dashboard/modules`.
- `<Module>ModuleParameterEnum` (in `src/Module/<Module>/Setting/`) — the
  module's **own** toggle enum carrying its cascade graph, labels,
  descriptions, and `getModuleId()`. **Monorepo-split (since 2026-05-30):**
  business modules NO LONGER use the central
  `Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum` — that one is
  core-infra only (General/Platform/Configuration/Media/Ged). Both core and
  client business modules now have the same shape; only the namespace differs
  (`Aurora\Module\X` vs `App\Module\X`).
- `<Module>ModuleParameterProvider implements ApplicationParameterProviderInterface`
  — yields the enum cases to `aurora:application-parameter` so the rows aren't
  flagged obsolete. Missing provider ⇒ the toggles get wiped on next sync.
- `Aurora\Module\Configuration\Setting\Configuration\ConfigurationTab::$moduleToggle`
  — optional field that hides a Settings tab when its owning module is
  disabled.

The canonical fix-up procedure is `/register-module-toggle`. This skill
only **audits** — it doesn't edit code.

## Inputs

- **Module name** (optional, PascalCase, e.g. `Notes`). If provided,
  audit only that module. If omitted, walk every module under
  `src/Module/*/` (and any client-side modules in the current
  project — e.g. `App\Module\<Module>` for aurora-client / aurora-welding).
- **Scope hint** (optional): `core-only`, `client-only`, or `all`
  (default `all`). Lets you narrow to one side when running inside a
  client project that has both vendor'd core modules and its own.

## Discovery

1. Glob `src/Module/*/[A-Z]*Module.php` (in aurora-core) and
   `src/Module/*/[A-Z]*Module.php` (in clients — both paths because
   `App\Module\` is the client namespace).
2. For each match, read the file just enough to extract:
   - The module's PascalCase id (from class name minus `Module` suffix)
   - The lower-case module id (via `getId(): string` body — or guess
     `snake_case` of the PascalCase id and verify)
   - Whether it implements `ModuleToggleProviderInterface`
3. Drop **infra modules** that are intentionally always-on. The current
   allowlist is:
   - `Configuration` — settings UI itself, no toggle (turning it off would
     hide the panel you'd use to turn it back on)
   - `Platform` — auth / users / permissions, must always be reachable
   - `Dev` — `/dev/dashboard/*` admin tools
   - `Media` — global media library, used cross-module
   - `General` — top-level dashboard
   For each of these, emit `⏭️ skipped (always-on infra)` and move on.

## Checks per module

Run the checks below **in order** for each non-skipped module. Use
`Read`, `Bash` (`grep`, `rg`, `find`). Do NOT modify files.

For each item: `✅` pass / `❌` fail / `⚠️` warning (e.g. optional because
the module has no settings tab).

### 1. Module class

1. `src/Module/<Module>/<Module>Module.php` exists.
2. The class `implements ModuleToggleProviderInterface` (grep the
   `implements` clause and the `use` line).
3. The class injects a `<Module>Context` in its constructor.

### 2. Toggle enum / provider wiring

Post-split, **core and client business modules have the same shape** — own
enum + own provider. Only the namespace prefix differs.

4. `src/Module/<Module>/Setting/<Module>ModuleParameterEnum.php` exists and
   implements `ApplicationParameterEnumInterface`, with a top-level case
   `case Backend = 'modules_<module_id>_backend';`. **Fail if the module's
   toggle still lives in the central `ModuleParameterEnum`** (a business
   module there is the #1 post-split regression).
5. `getLabel()`, `getDescription()` have an arm for `Backend`, and
   `getModuleId()` returns `'<module_id>'` for `Backend` (null otherwise).
   Confirm via the `match ($this) {…}` arms.
6. **For each NavItem** in `getCatalogNavSections()`, a matching sub-module
   case exists in the enum. The convention is
   `case <SubName> = 'modules_<module_id>_<sub_id>';`, with:
   - `getCascadeRequires()` returning `self::Backend->value`
   - `getDisplayParent()` returning `self::Backend->value`
7. `src/Module/<Module>/Setting/<Module>ModuleParameterProvider.php` exists,
   implements `ApplicationParameterProviderInterface`, and its
   `getParameters()` does `yield from <Module>ModuleParameterEnum::cases();`.
   Without it the toggle rows get wiped on the next
   `aurora:application-parameter` sync.
8. *(Packaged modules only — those with a `config/services.php`)* the
   provider is tagged. The base `config/services.php` always tags
   `ApplicationParameterProviderInterface` → `aurora.application_parameter_provider`,
   so this is satisfied automatically; flag only if the module ships a
   `config/services.php` that omits that `instanceof()` line.

### 3. Context + nav gating

9. `src/Module/<Module>/<Module>Context.php` exists.
10. `<Module>Context::isBackendEnabled(): bool` exists and reads
    `ModuleAccessChecker::isEnabled(<Module>ModuleParameterEnum::Backend->value)`
    — passing `->value` (string), not hardcoded `return true`.
11. `<Module>Module::getNavSections()` short-circuits on
    `!$<module>Context->isBackendEnabled()` (returns `[]`).
12. **Per NavItem**, `getNavSections()` checks the matching sub-toggle
    (e.g. `if ($ctx->isWorkflowsEnabled()) $items[] = $this->workflowsNavItem();`).
13. `getCatalogNavSections()` exists and returns the **full** nav-item
    list regardless of toggle state — the dashboard needs the catalog
    to display "this toggle controls these items" even when off.

### 4. `getToggles()` method

14. `<Module>Module::getToggles()` exists and returns a list of
    `ModuleToggle` instances built from `<Module>ModuleParameterEnum::<Case>->toToggle()`
    (one per enum case the module owns). Cross-check: the count must match
    the number of cases discovered in steps 4–6.

### 5. Translations

15. `backend.modules.<module_id>_backend` is defined in
    `src/Module/<Module>/translations/messages.fr.yaml` AND
    `messages.en.yaml`.
16. `backend.modules.<module_id>_backend_description` is defined in
    both locales.
17. Sub-module trans keys (`backend.nav.<route_id>` +
    `backend.nav.<route_id>_description`) exist for every NavItem — the
    convention is to reuse the existing nav-label keys for the
    sub-toggle, so this should already be the case for any module that
    rendered a NavItem before.

### 6. Settings tab (only if a `ConfigurationTabProvider` exists)

18. Find `src/Module/<Module>/Setting/<Module>ConfigurationTabProvider.php`.
    If absent, skip with `⏭️ no settings tab` — that's fine.
19. If present, the provider's module-owned tab(s) must declare
    `moduleToggle:` with the string key — both core and client modules now
    use `<Module>ModuleParameterEnum::Backend->value` (mirrors
    `CrmConfigurationTabProvider`). A bare string `'<setting_key>'` is also
    acceptable. Flag only a missing/`null` `moduleToggle:` on a module-owned
    tab.
20. The shared `sequences` tab (if contributed) MUST leave
    `moduleToggle: null`. It's cross-module by design.

## Output format

End with a single Markdown table summarizing every module:

```
| Module           | Enum | Provider | Context | Nav gate | getToggles | Trans | Settings tab |
|------------------|------|----------|---------|----------|------------|-------|--------------|
| Crm              | ✅    | ✅        | ✅       | ✅        | ✅          | ✅     | ✅            |
| Editorial        | ✅    | ✅        | ✅       | ⚠️ partial | ✅          | ✅     | ⏭️ no tab    |
| Project          | ❌ toggle still in central `ModuleParameterEnum` | … |
| Configuration    | ⏭️ skipped (always-on infra) |
```

The **Enum** column = the module's own `<Module>ModuleParameterEnum` (a
business module still using the central enum fails here). The **Provider**
column = `<Module>ModuleParameterProvider` exists and yields the cases
(missing ⇒ rows wiped on next sync).

Below the table, list each `❌` and `⚠️` with:
- The exact file + line (or "missing file") that's wrong
- The one-line fix or `/register-module-toggle` invocation

Finish with a one-sentence summary:
- `✅ All N modules pass.` — if no `❌`
- `⚠️ N modules need attention — run /register-module-toggle on …` — if any fail

## Examples

<example>
user: "vérifie que tous les modules ont bien leurs toggles"
assistant: [audits everything; finds Vault is missing
`getCatalogNavSections()` and Erp doesn't gate `getNavSections()`]

The report would surface:
```
| Vault     | ✅      | ✅       | ✅        | ✅          | ✅     | ⏭️ no tab    |
```
with a footnote: "⚠️ Vault — `getCatalogNavSections()` returns
toggle-filtered items, should be unfiltered. Fix: copy the body of
`getNavSections()` minus the `isBackendEnabled()` short-circuit."
</example>

<example>
user: "audit le module Crm"
assistant: [audits only Crm; reports each of the 20 checks]
</example>

## Boundaries

- **Read-only.** Never run `Edit` / `Write`. If a gap is found, point
  the user at `/register-module-toggle <Module>` or `/add-submodule
  <Module> <SubName>`.
- **Don't query the DB.** Whether the `core_settings` rows are seeded
  is a separate concern (handled by `aurora:application-parameter`);
  the audit is purely about source-code conformance.
- **No false positives on infra.** Always honour the skipped-modules
  allowlist (Configuration, Platform, Dev, Media, General). Adding
  toggles to those would break the admin panel itself.
- **Sub-modules count.** If a module has `N` NavItems in its catalog
  but only `M` sub-toggles in the enum, flag the difference — that's
  the most common scaffolding gap.
