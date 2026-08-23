---
name: add-entity
description: Scaffold a new Aurora entity with the 5-layer Sylius pattern. Reads templates under `.claude/skills/add-entity/templates/`, fills placeholders, writes files, patches `AuroraBundle::$resolve_target_entities`, then handles the design work (fleshing out AbstractX columns from the user's field list, drafting the index ViewBuilder, writing FR/EN translations, optionally chaining to `/add-crud-list-ui`). Use when the user asks to "create", "add", "scaffold", "générer", or "ajouter" a new entity, especially when they mention a backend CRUD page.
scope: core-only
---

# add-entity

Scaffold a new Aurora entity. The skill is the **only** entry point -
the previous `aurora:make:entity` CLI wizard was removed to prevent
drift (devs running it bare were skipping the smart-edit phase that
fleshes out `AbstractX` with the real fields, drafts the ViewBuilder,
and writes proper translations).

The shape of every generated file lives in
`.claude/skills/add-entity/templates/*.tpl`. The skill reads each
template, applies the placeholder substitution, and writes the result
via the `Write` tool. **Don't reimplement the file contents** - read
the template, substitute, write.

> Doc canonique : `docs/aurora-core/dev/entity_extensibility_convention.md`.
> Pour étendre une entité existante depuis un client : `/extend-aurora-entity`.
> Pour la UI list page une fois l'entité scaffoldée : `/add-crud-list-ui`.

## Required inputs (ask upfront if missing)

1. **Entity name** in PascalCase (`Workspace`, `Refund`, `AuditLog`).
2. **Module path** relative to `src/` :
   - `Core` (a core/global entity living directly under `src/Core/<Feature>/`)
   - `Module/<Module>` (e.g. `Module/Billing`, `Module/Editorial`)
   - The path must already exist. If not, stop and point at
     `/add-module` first.
3. **Plural** - defaults to `<Name>s`. Ask only if irregular
   (`Taxonomy` → `Taxonomies`).
4. **Backend CRUD?** (default yes) - skip Layers 2-5 if no (translation
   pivots, audit logs, sub-entities). Always generates Layer 1
   (Entity triplet + Repository).
5. **Fields** - the columns the user wants on `AbstractX`. The
   templates ship a single `name: string(150)` placeholder; you flesh
   out the real fields in Step 4a below. **Never invent fields** - ask
   explicitly for the list.
6. **Permission** (optional override) - defaults to
   `<module_id>.<plural_snake>.manage`. Ask only if non-standard.
7. **Audit channel** (optional override) - defaults to `core`. Used by
   `AuditLogger` (`<entity>.created` logged under `core/<entity>.created`).

## Step 1 - Derive the variable map

Compute from the entity name + module path :

| Variable | Derivation | Example (`Workspace` in `Module/Core`) |
|---|---|---|
| `{{NAME}}` | as-given (PascalCase) | `Workspace` |
| `{{NAME_CAMEL}}` | `lcfirst($NAME)` | `workspace` |
| `{{NAME_SNAKE}}` | snake_case | `workspace` |
| `{{PLURAL_NAME}}` | user input or `<Name>s` | `Workspaces` |
| `{{PLURAL_SNAKE}}` | snake_case plural | `workspaces` |
| `{{PLURAL_KEBAB}}` | kebab-case plural | `workspaces` |
| `{{NAMESPACE}}` | `Aurora\` + module path with backslashes + `<Name>` | `Aurora\Module\Core\Workspace` |
| `{{TWIG_NAMESPACE}}` | last segment of module path | `Core` |
| `{{TABLE_NAME}}` | `core_<plural_snake>` | `core_workspaces` |
| `{{SEQUENCE_NAME}}` | `seq_core_<name_snake>_id` | `seq_core_workspace_id` |
| `{{PERMISSION}}` | user input or `<twig_ns_lc>.<plural_snake>.manage` | `core.workspaces.manage` |
| `{{AUDIT_CHANNEL}}` | user input or `core` | `core` |

## Step 2 - Pick templates and target paths

Always generate (Layer 1) :

| Template | Target |
|---|---|
| `Interface.php.tpl` | `src/<ModulePath>/<Name>/Entity/<Name>Interface.php` |
| `Abstract.php.tpl` | `src/<ModulePath>/<Name>/Entity/Abstract<Name>.php` |
| `Entity.php.tpl` | `src/<ModulePath>/<Name>/Entity/<Name>.php` |
| `Repository.php.tpl` | `src/<ModulePath>/<Name>/Repository/<Name>Repository.php` |

If CRUD enabled (Layers 2-4) :

| Template | Target |
|---|---|
| `InputInterface.php.tpl` | `src/<ModulePath>/<Name>/Dto/<Name>InputInterface.php` |
| `Input.php.tpl` | `src/<ModulePath>/<Name>/Dto/<Name>Input.php` |
| `InputFactoryInterface.php.tpl` | `src/<ModulePath>/<Name>/Dto/<Name>InputFactoryInterface.php` |
| `InputFactory.php.tpl` | `src/<ModulePath>/<Name>/Dto/<Name>InputFactory.php` |
| `ManagerInterface.php.tpl` | `src/<ModulePath>/<Name>/Manager/<Name>ManagerInterface.php` |
| `Manager.php.tpl` | `src/<ModulePath>/<Name>/Manager/<Name>Manager.php` |
| `SerializerInterface.php.tpl` | `src/<ModulePath>/<Name>/Serializer/<Name>SerializerInterface.php` |
| `Serializer.php.tpl` | `src/<ModulePath>/<Name>/Serializer/<Name>Serializer.php` |

If CRUD AND user didn't skip the controller (Layer 5) :

| Template | Target |
|---|---|
| `Controller.php.tpl` | `src/<ModulePath>/<Name>/Controller/Backend/<Plural>Controller.php` |

## Step 3 - Read, substitute, write

For each chosen template :

1. `Read` `.claude/skills/add-entity/templates/<template>.tpl`.
2. Apply `str_replace`-style substitution for every `{{KEY}}` token.
3. `Write` the result to the computed target path.

The templates are mechanical - no logic, just placeholders. The hard
work is in Step 4.

## Step 4 - The smart post-edits (Claude-only work)

### 4a. Flesh out `Abstract<Name>` with the real fields

The template ships a single `name: string(150)` placeholder column. Edit
it to add every field the user listed :

- `protected <type> $<field>;` with `#[ORM\Column(...)]` (length /
  nullable / unique / `enumType` / type / etc.)
- Matching getter + setter returning `static` (for the fluent-chain
  convention).
- Reflect each non-id field in `<Name>Interface` (so client extensions
  rely on the contract).
- If the field needs validation, add `#[Assert\*]` constraints in
  `<Name>Input.php` AND surface the getter in `<Name>InputInterface.php`.

### 4b. Patch the Input + Factory for the new fields

In `<Name>Input.php` constructor :
- Add each field as `public readonly <type> $<name>` with `#[Assert\*]`.
- Add the `get<Field>()` method.
- Surface the getter in `<Name>InputInterface.php`.
- Extend `<Name>InputFactory::fromArray()` to read each field via
  `Str::trimFromArray($data, '<name>')` (or the appropriate parser).

### 4c. Adjust the Manager body

In `<Name>Manager::applyInput()`, mirror the field hydration :

```php
$entity->set<Field>($input->get<Field>());
```

Extend `auditPayload()` to include the new fields so audit log entries
carry the full state.

### 4d. Extend the Serializer

Add every new field to `<Name>Serializer::serialize()`. Format dates
as `DATE_ATOM`.

### 4e. Patch `src/AuroraBundle.php`

CRITICAL - without this, Doctrine relations targeting `<Name>Interface`
won't resolve to the concrete class on the client side.

1. Add two `use` clauses near the top-of-file `use` cluster :
   ```php
   use <NAMESPACE>\Entity\<Name>;
   use <NAMESPACE>\Entity\<Name>Interface;
   ```
2. Append the entry to `$resolve_target_entities` array (sorted
   lexicographically inside the module's block) :
   ```php
   <Name>Interface::class => <Name>::class,
   ```

Idempotent - if the entry is already there (re-running the skill), skip
the patch.

### 4f. Translations

Add the new keys in both `messages.fr.yaml` and `messages.en.yaml` in
the module's translations file :

```yaml
backend:
    nav:
        <plural_snake>: <Display name>
        <plural_snake>_description: <Tooltip>
    permissions:
        names:
            <module_id>:
                <plural_snake>:
                    manage: <Permission label>
    <plural_snake>:
        title: <Page title>
        col_name: <Name column header>
        col_actions: <Actions header>
        add: <New button>
        empty: <Empty state>
        deleted: <Toast on delete success>
        errors:
            name_required: <…>
            name_too_long: <…>
```

The error keys MUST match what `<Name>Input` references
(`backend.<plural_snake>.errors.name_required` etc.) - keep them in
sync.

### 4g. Index ViewBuilder + Twig + Vue (optional)

Out of the templates' scope (template structure depends on the real
fields). After fleshing out the backend :

- **ViewBuilder** : `src/<ModulePath>/<Name>/View/<Plural>ViewBuilder.php`.
  Pattern : `<Plural>Repository` → `['<plural_snake>' => [serialized
  rows], <other index-page payload>]`. Reference :
  `AgenciesViewBuilder` in `src/Module/Platform/Agency/View/`.
- **Twig template** : `src/<ModulePath>/templates/backend/<plural_snake>/index.html.twig`,
  extending the standard layout, mounting the Vue component.
- **Vue list page** : chain to `/add-crud-list-ui` to scaffold
  `<Plural>App.vue` + `use<Plural>Form.js` with the toolbar / table /
  modals.

## Step 5 - Migration + verify

```bash
make migration                              # generates src/Migrations/Version*.php
# Review the SQL by hand. Adjust if Doctrine's diff is over-eager
# (foreign keys, default values, etc.).
make migrate                                # applies
make cc                                     # cache:clear (mandatory after #[AsAlias])
php bin/console doctrine:schema:validate    # sanity check
make ft                                     # tests + lint
```

If the user mentioned a CRUD UI follow-up, chain to `/add-crud-list-ui`
once `make ft` is green.

## Boundaries

- **One entity per invocation.** The AuroraBundle patch is sequential;
  batching two entities would interleave the resolve_target_entities
  edits and require manual reordering.
- **Never run migrations or apply schema changes** - leave `make migrate`
  to the user after they've reviewed the SQL.
- **Never invent fields.** Ask the user for the list. The template
  ships the `name: string(150)` placeholder explicitly so you don't
  have to decide a column out of thin air.
- **Don't reimplement the templates.** Every file is read from a `.tpl`
  in `.claude/skills/add-entity/templates/`. If a convention evolves
  (new Manager hook, new DTO field, etc.), edit the template, not the
  skill.
- **Sub-DTOs stay `final readonly`.** Only the root DTO consumed by the
  controller gets the full quartet. If the entity has sub-DTOs (e.g.
  `PostTranslationInput` nested inside `PostInput`), generate them as
  `final readonly class` without an Interface / Factory.
- **No new module creation.** If the module path doesn't exist, stop
  and point at `/add-module`.
- **Apply the doc-audit convention** (cf.
  `process_doc_audit_before_commit.md`) : the new entity probably
  belongs in the "instrumented entities" table in
  `entity_extensibility_convention.md` - append the row.
