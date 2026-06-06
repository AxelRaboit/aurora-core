---
name: register-module-toggle
description: Register a module (and its sub-modules) in the `/dev/dashboard/modules` admin panel by wiring its OWN `<Module>ModuleParameterEnum` + `<Module>ModuleParameterProvider`, a `<Module>Context`, and the `ModuleToggleProviderInterface` on the module class. Use when the user asks "why does my module not show up in /dev/dashboard/modules", "the module is missing from the modules dashboard", "register Notes in the toggle dashboard", "expose toggles for <Module>", or when a fresh module/sub-module needs to become user-toggleable. Idempotent — re-running on an already-registered module is a no-op.
scope: shared
---

# register-module-toggle

Wire an existing Aurora module so its top-level toggle (and any
sub-module toggles) appear on the **`/dev/dashboard/modules`** admin
panel and gate the live nav at runtime.

This skill targets a module that already exists in `src/Module/<Module>/`
(or `src/Core/<Module>/` for core modules) but is **missing from the
dashboard** — typically because it was scaffolded without
`ModuleToggleProviderInterface` and without a `<Module>Context`.

> **Monorepo-split convention (since 2026-05-30).** Each business module
> owns its toggles in its **own** `<Module>ModuleParameterEnum` (under
> `src/Module/<Module>/Setting/`) + a `<Module>ModuleParameterProvider` —
> NOT the central `ModuleParameterEnum` (`src/Module/Configuration/Setting/Enum/`),
> which is now core-infra only (General/Platform/Configuration/Media/Ged).
> Mirror `src/Module/Notes/Setting/NotesModuleParameterEnum.php` (sub-toggles)
> or `src/Module/Photo/Setting/PhotoModuleParameterEnum.php` (frontend case).
> The provider is what keeps the toggle rows from being wiped by
> `aurora:application-parameter`. If the module is a standalone package, its
> `config/services.php` tags the provider; in the monorepo the central
> `_instanceof` does. Either way `getParameters()` must `yield from` the cases.

> **For a brand-new module** that needs scaffolding from scratch with
> toggles wired upfront, use `/add-module` instead. This skill is for
> retro-fitting an existing one.
>
> **For adding a single sub-module** under an already-registered parent,
> use `/add-submodule`. This skill is for registering a module whose
> parent toggle itself is missing.

## When to use

Symptoms that should trigger this skill:
- "Le module X n'apparaît pas dans `/dev/dashboard/modules`"
- "Je vois que la section Notes manque sur la page des modules"
- "On peut activer/désactiver Vault mais pas <Module>"
- After `/add-module` if you skipped the toggle wiring step
- After adding sub-modules to a module that never had a parent toggle

## Required inputs (ask upfront if missing)

1. **Parent module** (PascalCase) — must exist. Verify by globbing
   `src/Module/<Parent>/<Parent>Module.php` or `src/Core/<Parent>Module.php`.
   If neither found, stop and report.
2. **List of sub-modules** to register as nested toggles. Inspect the
   parent's `getNavSections()` for the existing `NavItem`s — each one is
   a candidate sub-module. Ask the user to confirm the list and labels.
   If the parent has zero sub-modules, only the parent toggle is wired.
3. **Trans key strategy** — by convention, the parent label/description
   uses `backend.modules.<module_id>_backend` and `backend.modules.<module_id>_backend_description`
   (in the module's `translations/messages.<locale>.yaml`). Sub-module
   toggles reuse the existing `backend.nav.<route_id>` keys — they're
   already defined for the NavItem.

## What gets generated/edited

### 1. Create the module's own `<Module>ModuleParameterEnum` + provider

File: `src/Module/<Module>/Setting/<Module>ModuleParameterEnum.php`
(create it — do **not** touch the central `ModuleParameterEnum`).

Mirror `NotesModuleParameterEnum` exactly. Case names are **short**
(`Backend`, `<Sub1>`, …) and the stored VALUE keeps the legacy key
(`modules_<module_id>_backend`) so no settings migration is needed :

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\<Module>\Setting;

use Aurora\Core\Module\Toggle\ModuleToggle;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

enum <Module>ModuleParameterEnum: string implements ApplicationParameterEnumInterface
{
    private const string GROUP = 'modules';

    case Backend = 'modules_<module_id>_backend';
    case <Sub1> = 'modules_<module_id>_<sub1_id>';
    // ... one case per sub-module

    public function getKey(): string { return $this->value; }

    public function getLabel(): string
    {
        return match ($this) {
            self::Backend => 'backend.modules.<module_id>_backend',
            self::<Sub1> => 'backend.nav.<sub1_route_id>',   // reuse the NavItem key
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Backend => 'backend.modules.<module_id>_backend_description',
            self::<Sub1> => 'backend.nav.<sub1_route_id>_description',
        };
    }

    public function getDefaultValue(): string { return '1'; }
    public function getType(): string { return 'bool'; }
    public function getGroup(): string { return self::GROUP; }
    public function getPlaceholder(): ?string { return null; }

    /** Module identifier for the top-level toggle, null for sub-toggles. */
    private function getModuleId(): ?string
    {
        return self::Backend === $this ? '<module_id>' : null;
    }

    /** Cascade dependency (parent that must be ON), null for the top-level. */
    private function getCascadeRequires(): ?string
    {
        return self::Backend === $this ? null : self::Backend->value;
    }

    /** Structural parent for dashboard grouping, null for the top-level. */
    private function getDisplayParent(): ?string
    {
        return self::Backend === $this ? null : self::Backend->value;
    }

    public function toToggle(): ModuleToggle
    {
        return new ModuleToggle(
            key: $this->value,
            labelKey: $this->getLabel(),
            descriptionKey: $this->getDescription(),
            parentKey: $this->getCascadeRequires(),
            moduleId: $this->getModuleId(),
            displayParentKey: $this->getDisplayParent(),
        );
    }
}
```

> The match arms (`getLabel` / `getDescription`) must be **exhaustive** —
> one arm per case, no `default`. That is the forcing function : adding a
> sub-module case without its arms is a compile error.

Then create the provider (so `aurora:application-parameter` doesn't flag
the rows obsolete and wipe them) :

File: `src/Module/<Module>/Setting/<Module>ModuleParameterProvider.php`

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\<Module>\Setting;

use Aurora\Module\Configuration\Setting\Provider\ApplicationParameterProviderInterface;

final readonly class <Module>ModuleParameterProvider implements ApplicationParameterProviderInterface
{
    public function getParameters(): iterable
    {
        yield from <Module>ModuleParameterEnum::cases();
    }
}
```

> The `_backend` suffix on the top-level key is mandatory (`modules_notes`
> would clash with the namespace of sub-module keys like
> `modules_notes_post_it`).

### 2. Create `<Module>Context.php`

File: `src/Module/<Module>/<Module>Context.php` (or `src/Core/<Module>/<Module>Context.php`
for core modules).

Mirror the `VaultContext` pattern exactly :

```php
<?php

declare(strict_types=1);

namespace Aurora\Module\<Module>;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\<Module>\Setting\<Module>ModuleParameterEnum;

final readonly class <Module>Context
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isBackendEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(<Module>ModuleParameterEnum::Backend->value);
    }

    public function is<Sub1>Enabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(<Module>ModuleParameterEnum::<Sub1>->value);
    }

    // ... one method per sub-module
}
```

> Pass `->value` (a string). The per-module enum does not satisfy the
> central `ModuleParameterEnum` type-hint, and `ModuleAccessChecker::isEnabled()`
> accepts `ModuleParameterEnum|string`.

### 3. Refactor `<Module>Module.php`

The existing class needs three changes :

#### a) Add `ModuleToggleProviderInterface` to the `implements` list

```php
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;

final readonly class <Module>Module implements ModuleInterface, ModuleToggleProviderInterface
```

#### b) Inject `<Module>Context` via constructor

```php
public function __construct(private <Module>Context $<module>Context) {}
```

#### c) Gate `getNavSections()` on the toggles

```php
public function getNavSections(): array
{
    if (!$this-><module>Context->isBackendEnabled()) {
        return [];
    }

    $items = [];

    if ($this-><module>Context->is<Sub1>Enabled()) {
        $items[] = new NavItem(/* existing args, unchanged */);
    }
    // ... one if per sub-module

    if ([] === $items) {
        return [];
    }

    return [new NavSection('<module_id>', $items, priority: <existing>)];
}
```

**Do not touch `getCatalogNavSections()`.** It must always return the
full list (the catalog is the picker UI for module-by-module config —
it shows *all* available items regardless of current toggle state).

#### d) Add `getToggles()` method

```php
public function getToggles(): array
{
    return [
        <Module>ModuleParameterEnum::Backend->toToggle(),
        <Module>ModuleParameterEnum::<Sub1>->toToggle(),
        // ... one per sub-module
    ];
}
```

(Add `use Aurora\Module\<Module>\Setting\<Module>ModuleParameterEnum;` to
`<Module>Module.php`.)

### 4. Add translations

Append to `src/Module/<Module>/translations/messages.fr.yaml` and `.en.yaml` :

```yaml
backend:
  modules:
    <module_id>_backend: <Display name>
    <module_id>_backend_description: <One-line description of what this module enables>
```

Sub-module trans keys (`backend.nav.<route_id>` + `_description`) are
**already present** — they were created when the NavItem was defined.
Do not re-add them.

### 5. Run post-generation commands

```bash
# Seed the new settings in core_settings (creates rows with default '1')
make sf CMD="aurora:application-parameter"

# Mandatory after DI changes (new Context service)
make cc

# Validate everything compiles + tests pass
make ft
```

The `aurora:application-parameter` output should show:
```
+ modules_<module_id>_backend (défaut : 1)
+ modules_<module_id>_<sub1_id> (défaut : 1)
…
[OK] N créé(s), …
```

If `N` differs from the number of cases you added, something didn't
wire — re-check the enum cases and `getToggles()` method.

## Runtime gating — default behavior

`ModuleAccessChecker::getGlobal()` calls `SettingRepository::getBoolean($key, true)`,
which **defaults to `true`** when the row is missing. So users see no
visible change until they actively toggle something OFF in the
dashboard. The seed step is for surfacing the toggles in the UI, not
for changing visibility.

## Auto-discovery — what works without extra wiring

- Symfony autowires the `<Module>Context` into the module
  constructor (both are services, both `final readonly`).
- `ModuleToggleRegistry` collects toggles from every service implementing
  `ModuleToggleProviderInterface` (tagged automatically) — no manual
  registration needed.
- The dashboard reads the toggles returned by every `getToggles()`, so the
  new `<Module>ModuleParameterEnum` cases show up immediately after
  `aurora:application-parameter` seeds their rows.
- `<Module>ModuleParameterProvider` is auto-tagged
  `aurora.application_parameter_provider` — by the module's own
  `config/services.php` if it's a package, else by the central `_instanceof`.
  Without the provider, the sync command would flag the rows obsolete.

## Boundaries

- **One module per invocation.** If the user has two modules to
  register, run the skill twice — each module's enum + Context + Module
  edits should be a separate atomic change.
- **Never create the parent module from scratch.** If the parent doesn't
  exist, point at `/add-module`.
- **Never add sub-module entries that don't have a NavItem.** The
  dashboard shows nav-item-backed toggles. Pure-data sub-modules (no
  visible UI) shouldn't appear in the panel.
- **Do not modify `getCatalogNavSections()`.** It's the picker UI — must
  show everything regardless of toggle state.
- **Always pair `getCascadeRequires()` and `getDisplayParent()`** for the
  same sub-module — they encode the cascade rule and the visual hierarchy
  respectively, and the dashboard cross-references both.
- **Never touch the central `ModuleParameterEnum`.** Business-module
  toggles live in the module's own `<Module>ModuleParameterEnum`. The
  central enum is core-infra only.

## Output to the user

End with a summary listing :
- The new `<Module>ModuleParameterEnum` + `<Module>ModuleParameterProvider`
  (with the enum case keys)
- The new `<Module>Context.php` file
- The `<Module>Module.php` edits (interface + constructor + gating + getToggles)
- The trans keys added (label + description)
- A confirmation that `aurora:application-parameter` created the
  expected count of new settings
- A reminder that the runtime gating defaults to enabled (`'1'`) so
  visible behavior is unchanged until the user toggles something OFF
