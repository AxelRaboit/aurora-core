---
name: check-extensibility
description: Audit an Aurora entity against the 5-layer extensibility convention (Sylius-style). Use when the user asks to "check", "audit", "vérifier", or "valider" the extensibility of a named entity, OR after creating/modifying an entity that has a backend CRUD page. Outputs a structured report of conformance gaps against docs/aurora-core/dev/entity_extensibility_convention.md.
scope: core-only
---

# check-extensibility

Audit one Aurora entity against the extensibility convention. The canonical
spec lives at `docs/aurora-core/dev/entity_extensibility_convention.md` - this
skill operationalizes its hard rules into a mechanical checklist.

## Inputs

- **Entity name** (e.g., `Agency`, `Post`, `Order`) - required. If the user
  did not provide one, ask before proceeding.
- **Module** - inferred by globbing `src/**/<Name>/Entity/<Name>.php`. If
  ambiguous (multiple matches), ask.

## What to check

Run the checks below **in order**. For each, report ✅ / ❌ / ⚠️ (warning, e.g.
optional layer absent because the entity has no backend CRUD).

Use `Read`, `Bash` (`grep`, `rg`, `find`), and the project structure.
Do NOT modify files - this skill is read-only.

### Layer 1 - Entity

In `src/<Module>/<Name>/Entity/` (or `src/Module/<Module>/<Name>/Entity/`):

1. `<Name>Interface.php` exists.
2. `Abstract<Name>.php` exists and uses `#[ORM\MappedSuperclass]`.
3. `<Name>.php` exists, is **not** `final`, declares `#[ORM\Entity]`.
4. Sequence is named exactly `seq_core_<snake_entity>_id` (grep the entity
   file for `SequenceGenerator` and `sequenceName:`). Reject any other prefix.
5. `<Name>::class` is listed as a key in
   `src/AuroraBundle.php` → `$resolve_target_entities`.

### Layer 1bis - Repository

In `src/<Module>/<Name>/Repository/<Name>Repository.php`:

6. Class extends `Aurora\Core\Repository\ResolveTargetEntityRepository`
   (NOT `ServiceEntityRepository`).

### Layer 2 - DTO (only if backend CRUD)

Check whether a CRUD controller exists: `find src -path "*<Name>/Controller/Backend*"`.
If none, mark layers 2-5 as ⚠️ "n/a - no backend CRUD" and skip.

In `src/<Module>/<Name>/Dto/`:

7. `<Name>InputInterface.php` exists.
8. `<Name>Input.php` exists, is **not** `final`, implements
   `<Name>InputInterface`. Class declaration must NOT use `readonly class`
   (individual `public readonly` props only - grep for `^readonly class`).
9. No static `fromArray()` method on `<Name>Input` (grep for
   `public static function fromArray`).
10. `<Name>InputFactoryInterface.php` exists.
11. `<Name>InputFactory.php` exists, carries
    `#[AsAlias(<Name>InputFactoryInterface::class)]`.

### Layer 3 - Manager (only if Manager exists)

The Manager MUST live in `Manager/`, not `Contract/`. If you find a
`Contract/` directory containing `<Name>ManagerInterface.php`, that's a
hard ❌ (legacy location).

In `src/<Module>/<Name>/Manager/`:

12. `<Name>ManagerInterface.php` exists.
13. `<Name>Manager.php` is **not** `final`, **not** `readonly class`.
14. Carries `#[AsAlias(<Name>ManagerInterface::class)]`.
15. Constructor properties are `protected readonly` (NOT `private readonly`).
    Grep for `private readonly` in the file - any hit is ❌.
16. **Instantiation hooks** - for every `new <X>()` in the file (excluding
    DTOs and value objects), there must be a `protected function create<X>()`
    that returns the interface. Grep `new [A-Z][a-zA-Z]+\(\)` in the Manager,
    then check each has a matching `protected function create<X>`.
17. **Hydration hook** - `protected function applyInput(` exists, UNLESS
    the entity qualifies for the User-style variant (≥6 specialized public
    methods, no simple create+update flow, distinct security per operation).
    Only `User` currently qualifies - for anything else, missing `applyInput`
    is ❌.
17b. **If `applyInput()` is missing, verify it's a legitimate User-style variant** :
    - Count public methods on the Manager (must be ≥6 specialized, beyond
      basic create/update/delete) → grep `public function` and inspect
    - No simple create+update flow with a shared DTO → check Controller :
      if it just dispatches to `manager->create($input)` / `manager->update($entity, $input)`,
      that's NOT User-style
    - Distinct security/validation per operation → check `#[IsGranted]` per
      Controller method differs from a single class-level grant
    If any criterion fails → ❌ (`applyInput()` should exist).
    Reference list of legitimate variants : User, Order, Invoice, Tiers, OcrJob,
    Comment (per `decision_variant_user_style.md`). Anything else missing
    `applyInput()` is a bug.
18. **Audit hooks** - if the Manager uses `AuditLogger`, then
    `protected function auditCreated`, `auditUpdated`, `auditDeleted`, and
    `auditPayload` all exist. Inline domain events (paid, validated, …) are
    fine but should splat-merge `$this->auditPayload(...)`.

### Layer 4 - Serializer (only if entity is JSON-serialized for the front)

In `src/<Module>/<Name>/Serializer/`:

19. `<Name>SerializerInterface.php` exists.
20. `<Name>Serializer.php` is **not** `final`, carries
    `#[AsAlias(<Name>SerializerInterface::class)]`.

### Layer 5 - Vue (only if backend CRUD page exists)

In `src/Module/<Module>/assets/backend/<plural>/`
(or `src/Core/assets/backend/<plural>/` for Core entities):

21. `<Plural>App.vue` exists.
22. Declares prop `extraFields` (grep for `extraFields`).
23. Exposes the three slots: `extra-headers`, `extra-cells`,
    `extra-form-fields` (grep `name="extra-`).
24. Composable `useXxxForm.js` (or `useXxxEdit.js` / `useXxxCreate.js` for
    the Theme/User variant) accepts an `extraFields` option AND submits the
    full form. Two acceptable shapes:
    - Literal spread: grep for `\.\.\.(editForm|form)` in a `request(...)`
      call.
    - Via `useFormAction`: the abstraction handles
      the spread internally. In that case, verify that `empty()` and
      `fromEntity()` both merge `Object.fromEntries(Object.entries(extraFields)...)`
      so client extras land in the form. This is the canonical Agency
      pattern - accept it as ✅.

### Controller wiring

In `src/<Module>/<Name>/Controller/Backend/`:

25. Controller constructor type-hints the **interfaces**, not the concrete
    classes:
    - `<Name>ManagerInterface`
    - `<Name>InputFactoryInterface`
    - `<Name>SerializerInterface`

### Module toggles (only if the entity belongs to a toggleable sub-feature)

26. **Out of scope for entity audit, but worth a quick look** : if the entity
    is part of a sub-feature that has its own enable/disable toggle (e.g.,
    Vault.Safe, Vault.PasswordGenerator), the parent `<Module>Module.php`
    should implement `Aurora\Core\Module\Contract\ModuleToggleProviderInterface`
    and the Controller / Nav should gate via `<Module>Context`. This is a
    module-level concern - flag it as a note ("toggle present ✅" / "toggle
    missing, consider adding ⚠️"), don't ❌ if missing.
    Cf. `pattern_user_scoped_module_access.md` and the Vault example.

## Output format

Produce a compact markdown report. One section per layer. For each check, a
single line. End with a "Verdict" summary line.

```
# Extensibility audit - <Name>

## Layer 1 - Entity
✅ Interface, Abstract, concrete present
✅ Sequence `seq_core_agency_id` correct
❌ Not registered in AuroraBundle::$resolve_target_entities

## Layer 2 - DTO
✅ …

…

## Verdict
3 ❌  /  1 ⚠️  /  21 ✅  → not extensible: fix the 3 ❌ before considering this entity ready.
```

Group the ❌ items into an actionable fix list at the bottom, each line
quoting the file + the exact change needed (e.g., "Remove `final` keyword
from `src/Core/Agency/Manager/AgencyManager.php:14`").

## Boundaries

- **Read-only.** Never edit, never run `php bin/console` mutations. If the
  user asks to fix the gaps after the audit, that's a separate request - do
  it as normal editing work, don't extend this skill.
- **One entity at a time.** If the user asks to audit "all entities", ask
  which one to start with, or propose looping the skill (but each invocation
  audits one).
- **Trust the convention doc.** When in doubt about a rule, re-read the
  relevant section of `entity_extensibility_convention.md` rather than
  guessing. The doc is authoritative; this skill is just a checklist over it.
