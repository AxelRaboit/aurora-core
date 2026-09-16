# Aurora Architecture

Aurora is a platform built on **Symfony 7 / PHP 8.4 / Vue 3 / Vite**. It ships as
the Composer package `axelraboit/aurora` and is consumed by client projects
(`aurora-client` and friends), which extend it without forking it.

> **This document describes the repository as it stands on 2026-09-16.**
> It was rewritten from scratch on that date: the previous version described
> ten business modules (Crm, Erp, Billing, Ecommerce, Photo, Project, Hr, …)
> extracted from this monorepo in July 2026, an `App\` namespace that has since
> become `Aurora\`, an `admin/` asset folder that is now `backend/`, and a dozen
> manual registration steps that are all auto-discovery today. Read
> `src/Module/` for the live inventory; module names below are the real ones.

---

## 1. Overview

```
src/
  AuroraBundle.php   -- the bundle: auto-discovers modules, prepends their config
  Core/              -- reusable infrastructure, knows nothing about any module
  Module/
    Configuration/   -- settings, storage backends, themes
    Dev/             -- /dev tooling: audit log, mount points, prerequisites
    Documentation/   -- the manual, shipped as Markdown in the package
    Editorial/       -- CMS: posts, post types, taxonomies, menus, comments, forms, SEO
    Ged/             -- documents: files, folders, categories, tags, Pexels import
    General/         -- dashboard, profile, cross-module search and trash
    Notes/           -- Markdown notes, wiki-links, sharing
    Planning/        -- calendars, events, reminders, recurrence, feeds, sync
    Platform/        -- auth, users, privileges
    Studio/          -- what is sold and what is delivered: customers, contracts,
                       decks, client spaces and their content

migrations/          -- hand-written, one per change (see §6.3)
config/              -- the app's own config; modules prepend theirs from the bundle
templates/bundles/   -- Twig bundle overrides only; module templates are co-located
```

Every module is a **plain directory**. No `composer.json`, no bundle class, no
`config/services.php` of its own. This reverses the May 2026 convention that
made each module a self-contained Composer package: Editorial was rebuilt into
core in August 2026 because the multi-repo cost more than it gave (manual
Composer bumps, a dev loop through a GitHub zip, and a presentation layer
already split, since the default theme's templates lived in core anyway).

---

## 2. Core (`src/Core/`)

Core holds every concern reusable across modules. It must not import from
`Aurora\Module\*`; where it needs a module's behaviour, it declares an
interface the module implements.

| Domain | What it contains |
|---|---|
| `Bootstrap` | `BootstrapRunner` and its providers - what a fresh install needs before anyone logs in |
| `Bundle` | `AbstractAuroraModuleBundle`, `AuroraModuleBundles` - the extension point a client bundle uses |
| `Contact` | `ContactSignalEvent` - how a form submission reaches whoever cares |
| `Content` | Block rendering and sanitising, embed resolution, value normalisation |
| `Dashboard` | `DashboardStatsProviderInterface` - what each module contributes to the home screen |
| `Encryption` | Doctrine types and subscribers for encrypted columns |
| `Enum` | `HttpMethodEnum`, `HttpStatusEnum`, `AppVersionEnum` |
| `EventSubscriber` | Maintenance mode, `X-Sendfile`, and the route gates that enforce module toggles |
| `Frontend` | `FrontendInterface`, the public-site controllers, theme resolution, view builders |
| `Http` | `JsonRequestTrait`, `JsonResponseTrait`, `JsonErrorCode` - the JSON contract every controller shares |
| `Locale` | `Locale` entity, `LocaleEnum`, the request subscriber that picks one |
| `Mail` | Mailer service and its templates |
| `Migration` | Migration status service, surfaced in the dev panel |
| `Module` | `ModuleInterface`, the registry, nav objects, toggles, `ModulePermissionVoter` - see §3 |
| `Money` | The currency enum, so amounts are not bare integers |
| `Notification` | In-app notifications: entity, manager, controller |
| `Reference` | `EntityReferenceResolver` - turns an entity into a label and a link, across modules |
| `Repository` | `ResolveTargetEntityRepository` and traits - the base every module repository extends |
| `Routing` | `AuroraModuleRouteLoader` (§5.4), `PathTemplateGenerator` |
| `Scheduler` | Symfony Scheduler wiring, `RecurringMessageProviderInterface` |
| `Scheduling` | `EntityScheduledEvent` / `EntityUnscheduledEvent` - how any module announces a date without knowing who listens |
| `Search` | The provider interfaces, relevance sorting, snippet building |
| `Sequence` | `SequenceGenerator`, `SequencePrefixEnum`, the resync command - see §6.1 |
| `Storage` | `StorageAdapterInterface`, `StorageDiskEnum` (local, r2), the file server, access rules |
| `Support` | `Arr`, `Num`, `Str`, `ChartPalette`, `TreeReorderParser` |
| `Testing` | Concerns shared by the test suite |
| `Timestampable` | The interface and trait every entity uses for `createdAt` / `updatedAt` |
| `Trash` | `TrashSourceInterface`, `TrashItem` - the shared bin every module contributes to |
| `Twig` | The extensions: appearance, file size, locale, path templates, migration status |
| `Validation` | The DTO argument resolver and its exception handling |

### Rule

Core defines contracts; modules implement them. `Trash`, `Search`,
`Dashboard`, `Reference` and `Scheduling` are all the same shape: an interface
in Core, an implementation per module, a tagged iterator in between. That is
how a module contributes to a cross-cutting surface without Core knowing it
exists, and how removing a module removes its contribution with it.

---

## 3. Module system

### 3.1 `ModuleInterface`

```php
interface ModuleInterface
{
    public function getId(): string;                 // 'studio', 'editorial', …
    public function getNavSections(): array;         // NavSection[], toggle-filtered
    public function getCatalogNavSections(): array;  // NavSection[], unfiltered
    public function getPermissions(): array;         // NavPermission[]
}
```

`getNavSections()` is what the sidemenu renders, gated on the module's toggles.
`getCatalogNavSections()` returns the same items **ungated**, because the
per-user module picker has to show what exists in order to offer it.

Modules are tagged automatically. `config/services.yaml` carries

```yaml
_instanceof:
    Aurora\Core\Module\Contract\ModuleInterface:
        tags: [aurora.module]
```

so there is nothing to register by hand.

### 3.2 Optional contracts

| Interface | What it adds |
|---|---|
| `ModuleToggleProviderInterface` | `getToggles()` - the module appears on `/dev/dashboard/modules` and can be switched off |
| `ModuleNavViewProviderInterface` | A module-level nav view, optionally with a Vue side panel (`Notes` uses it for its note tree) |
| `FrontendInterface` | The module has a public site (`Editorial`, `Ged`) |

### 3.3 Toggles

**There is exactly one toggle enum**:
`src/Module/Configuration/Setting/Enum/ModuleParameterEnum.php`. Every module's
cases live in it, prefixed by their module (`StudioSpaces`, `NotesMarkdown`),
and one provider, `CoreModuleParameterProvider`, yields them all to
`aurora:application-parameter`. There is no `<Module>ModuleParameterEnum`; that
belonged to the abandoned package split.

Each module wraps its cases in a `<Module>Context` with one `is<X>Enabled()`
per case, and gates its nav on it. Toggles default to **on** when the settings
row is absent, so adding one changes nothing until somebody turns it off.

`Dev` and `Documentation` have no toggle, and each says why in its class
docblock: the dev panel is gated by `ROLE_DEV` and no client should be able to
switch it off, and whoever may open the back office may read the manual.

### 3.4 Roles and privileges

Three flat roles, no hierarchy of features:

| Role | Description |
|---|---|
| `ROLE_DEV` | Bypasses every privilege check |
| `ROLE_ADMIN` | Full access to module features |
| `ROLE_USER` | Only what has been granted explicitly |

**Privileges** are strings (`studio.spaces.view`) stored as a JSON array on the
user. Each module declares the ones it owns through `NavPermission`, and
`ModulePermissionVoter` resolves `#[IsGranted('studio.spaces.view')]`: dev and
admin always pass, a plain user passes when the string is in their list.

```bash
make sync-privileges   # aurora:privileges:sync - purges strings no module claims
```

---

## 4. Conventions

### 4.1 Namespaces

```
Aurora\Core\<Domain>\<Layer>\<ClassName>
Aurora\Module\<Name>\<Domain>\<Layer>\<ClassName>
```

A client project mirrors the second with `App\Module\<Name>\…`.

### 4.2 What is auto-discovered, and the one thing that is not

`AuroraBundle::prependExtension()` globs `src/Module/*` and registers, for every
module it finds: its Doctrine mapping (`Aurora\Module\<Name>` → its directory),
its Twig namespace (`@<Name>` → `src/Module/<Name>/templates`), its translation
paths, and its routes. `config/packages/doctrine.yaml` and `twig.yaml` carry no
per-module block, and adding one would be a mistake.

**The exception is `resolve_target_entities`.** Every entity interface has to be
mapped to its concrete class in `src/AuroraBundle.php` by hand. That single list
is what lets a client swap any Aurora entity for its own subclass, and it is the
only wiring a new entity costs.

### 4.3 Templates

Co-located: `src/Module/<Name>/templates/backend/<feature>/index.html.twig`,
addressed as `@<Name>/backend/<feature>/index.html.twig`. Core's own live in
`src/Core/templates/` under `@Core`, `@Shared` and `@AuroraTheme`. The root
`templates/` directory holds Twig bundle overrides and nothing else.

### 4.4 Assets and Vue components

One glob in `src/Core/assets/app.js`:

```js
import.meta.glob("../../Module/**/assets/**/*.vue")
```

`**/assets/` accepts any number of feature folders between the module and
`assets/`, and `MODULE_PATH_RE` flattens them away. The component key is always
the module name plus whatever follows `assets/`:

```
src/Module/Ged/assets/backend/documents/App.vue            → ged/backend/documents/App
src/Module/Studio/SpaceContent/assets/backend/content/X.vue → studio/backend/content/X
```

Both layouts are valid. Nine modules keep their assets at the module root;
Studio co-locates them with each sub-domain. The trade-off is the alias:
`moduleAlias()` in `aliases.js` resolves exactly `src/Module/<Name>/assets`, so
a co-locating module has no `@<name>` alias and imports by relative path.

Mount from Twig with `vue_component('<key>')`. There is no glob or alias to add
per module - only the optional `aliases.js` line.

### 4.5 Routes

`#[Route]` attributes, loaded per module by `AuroraModuleRouteLoader`. A client
adds one `type: aurora_modules` routing entry and gets every module's routes.

Prefixes carry meaning and the firewalls depend on them: `^/(backend|dev|workspace)`
is the `admin` firewall, everything else is the front. `/workspace` is the client
space's own prefix, deliberately outside `/backend` because the surface is
delivered to clients rather than operated by staff.

### 4.6 Client extensions

A client project sets `AURORA_CLIENT_DIR`, which Vite maps to `@client`.
`app.js` scans `@client/src/Module/**/assets/**/*.vue` with the same key
convention, and a client component that lands on an existing key **wins**, since
the client map is spread after Aurora's. That is the override mechanism: put a
`.vue` at the same key to replace one, co-located with whatever PHP extension
goes with it.

For entities, the five-layer convention (`docs/aurora-core/dev/entity_extensibility_convention.md`)
is what makes the swap possible; `resolve_target_entities` is where it is
declared.

### 4.7 Adding a module

Use `/add-module`, which does the scaffolding and the edits below. By hand, in
order:

1. `src/Module/<Name>/` with its domain subfolders.
2. `<Name>Module.php` implementing `ModuleInterface`, plus a `<Name>Context`.
3. A `<Name>Backend` case in the central `ModuleParameterEnum`, with its
   `getLabel()` / `getDescription()` arms and `getModuleId()`.
4. `translations/messages.{fr,en}.yaml` in the module. Spanish only for what a
   customer reads - the back office falls back to French by design, and
   `CustomerFacingLocaleTest` enforces the split.
5. Optionally `aliases.js` (§4.4).
6. Entities through `/add-entity`, which handles the five layers and the
   `resolve_target_entities` line.

```bash
make module-sync   # privileges + install + parameters + translations + build
make ft
```

---

## 5. The modules

The list changes; read `src/Module/`. What is worth knowing here is the shape
they share: every one of them is a folder of sub-domains, each sub-domain a
five-layer slice (Entity / Dto / Manager / Repository / Serializer) plus its
`Controller`, `View`, `templates` and `assets`.

`Studio` is the best current example of a module that is more than CRUD: it
holds what is sold (`Customer`, `Contract`, `Deck`) and what is delivered
(`CustomerSpace`, `SpaceContent`, `SpaceAccess`), with the delivery surface
served under `/workspace` to people who are not staff.

---

## 6. Two pairs that are easy to confuse

### 6.1 Entity ids and business references

| Family | Mechanism | Owner |
|---|---|---|
| Entity primary keys | PostgreSQL sequence `seq_core_<entity>_id` | Doctrine migrations |
| Business references | Table `app_sequence_counters` | `SequenceGenerator` |

Every primary key uses Doctrine's `SEQUENCE` strategy with an explicit named
sequence, so the sequences are visible and manageable in PostgreSQL rather than
being silent `IDENTITY` columns. A client's entities use `seq_app_<entity>_id`.

Entities that carry a human-readable `reference` (`ART-000032`, `USR-000004`)
get it from `SequenceGenerator::next()` or `nextYearly()`. Prefixes are
configurable under **Réglages → Séquences**; the defaults live in
`SequencePrefixEnum`. The counters table is plain Doctrine, keyed
`(prefix, year)` with `year = 0` for a global sequence, incremented atomically
through `INSERT … ON CONFLICT DO UPDATE RETURNING`. A prefix row appears on
first use; there is nothing to seed.

```bash
make sync-sequences   # resets seq_core_*_id to MAX(id)+1, after imports or fixtures
```

### 6.2 Class names and table names

The namespace is the prefix, so class names stay clean:

```
Aurora\Module\Studio\Customer\Entity\Customer          ✅
Aurora\Module\Studio\Customer\Entity\StudioCustomer    ❌
```

Table names, on the other hand, are always prefixed - `core_studio_customers`,
`core_space_content_items` - so a query in a log says which module it came from
and two modules cannot collide on a common noun.

### 6.3 Migrations are written by hand

`doctrine:migrations:diff` is **not usable in this repository today**. The
initial migration creates the tables of the thirteen removed modules and nothing
has ever dropped them, so the diff proposes deleting sixty-five tables on every
run. Generate it if you like, keep only the statements naming your own tables,
and write the migration by hand with a docblock saying why each foreign key has
the `onDelete` rule it has.

This is a known debt with a known fix (one migration dropping the orphan tables,
columns and sequences), not a convention.

---

## 7. Related documents

- `docs/aurora-core/dev/entity_extensibility_convention.md` - the five layers, in detail
- `docs/aurora-core/dev/add_module.md` - the module checklist, long form
- `docs/aurora-core/dev/extending_aurora.md` - what a client project can change
- `src/Module/Documentation/content/` - the end-user manual, shipped in the package
