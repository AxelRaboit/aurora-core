# Aurora Architecture

Aurora is a platform built on Symfony 7 / PHP 8.3 / Vue 3 / Vite, designed to host
multiple independent business modules (Editorial CMS, CRM, ERP, Billing, Ecommerce, Photo, …)
on top of a shared Core infrastructure.

> ⚠️ **Read the module names here as illustrations, not as an inventory.**
> This document describes the *patterns* a module follows. Most of the
> modules it names (Editorial, Crm, Erp, Billing, Ecommerce, Photo, …) were
> extracted from this monorepo in July 2026 and no longer live under
> `src/Module/`. For what is actually installed today, read that directory
> and `docs/aurora-core/todo/module_roadmap.md`.

---

## 1. Overview

```
src/
  Core/         -- reusable infrastructure, independent of any business module
  Module/
    Billing/    -- invoices, suppliers (Tiers), OCR pipeline
    Crm/        -- CRM (contacts, companies, deals, …)
    Ecommerce/  -- shop (listings, cart, orders, payments)
    Editorial/  -- editorial CMS (posts, taxonomies, comments, forms, SEO)
    Erp/        -- ERP (products, inventory)
    Ged/        -- document management (documents, categories)
    Photo/      -- client gallery delivery (galleries, items, invites)
    Hr/         -- human resources (employee records)
    Planning/   -- planning & agenda (plannings, events)
    Project/    -- project management (projects, tasks, sprints, kanban)

templates/
  Core/         -- admin templates for Core domains
  Module/
    Editorial/  -- admin templates for the Editorial module   (@Editorial)
    Crm/        -- admin templates for the CRM module         (@Crm)
    Erp/        -- admin templates for the ERP module         (@Erp)
    Ged/        -- admin templates for the GED module         (@Ged)
    Hr/         -- admin templates for the Hr module           (@Hr)
    Planning/   -- admin templates for the Planning module    (@Planning)
    Project/    -- admin templates for the Project module     (@Project)
  front/        -- front-end theme templates (kept flat, see §5.3)
  shared/       -- base layout, shared components, emails     (@Shared)

src/Core/assets/
  backend/      -- Vue controllers and sub-components for Core backend areas
  frontend/    -- Vue controllers and sub-components for the public frontend
  shared/       -- cross-cutting Vue components, composables, utils, enums
  utils/        -- generic JS utilities (enums, helpers)
  locales/      -- generated translation JSON
  stimulus/     -- Stimulus controllers (renamed from controllers/)
  (root)        -- entrypoints: app.js, flash.js, theme.js, admin/guest/i18n.js

src/Module/<Module>/assets/
  backend/      -- Vue controllers and sub-components for the module backend
  frontend/    -- Vue controllers for the public frontend (if any)
```

---

## 2. Core (`src/Core/`)

Core contains every concern that is reusable across any business module. It must not
import anything from `App\Module\*`.

| Domain     | What it contains |
|------------|-----------------|
| User       | Entity, Managers (UserManager, FrontUserManager), Repository, DTO, Serializer, Enum (UserRole, UserStatus, UserType), Command |
| Auth       | Entities (AccessRequest, ResetPasswordRequest), Managers (AccessRequest, Invitation, PasswordReset, EmailVerification), Security (providers, checkers, authenticator, entry point), EventListeners, Validator, Controllers, DTO |
| Setting    | Entity (Setting), Repository, Enum (ApplicationParameter - includes Sequences group), Command, Controller |
| Sequence   | `SequenceGenerator` (PostgreSQL NEXTVAL wrapper), `SequencePrefixEnum` (canonical prefix per entity), `ResyncSequencesCommand` (`aurora:sequences:resync`) - see §6.8 |
| Theme      | Entity, Manager, Repository, Services (ThemeContext, ThemeResolver), DTO, Serializer, Controller |
| Locale     | Entity, Repository, Enum (LocaleEnum), EventSubscriber |
| Menu       | Entities (Menu, MenuItem, MenuItemTranslation), Manager, Repository, Services, DTO, Serializer, Twig extension, Command, Controller |
| Module     | ModuleInterface, ModuleRegistry, PermissionRegistry, ModulePermissionVoter, NavItem, NavSection, NavPermission - see §3 |
| Profile    | Controller |
| Search     | Service (SearchSnippetBuilder), Command, Controller |
| Dashboard  | Service (AdminStatsService), Controllers |
| Audit      | Entity (AuditLog), Service (AuditLogger) - records cross-module actions |
| Frontend   | Services (FrontContext, HttpCacheService) |
| Validation | Service (PayloadValidator), ArgumentResolver, DTO (PaginationRequest) |
| (misc)     | Twig extensions, Enums (HttpMethod), Support (Str), Trait (Timestampable, TimestampableTrait), Scheduler |

### Rule

A class belongs in Core if it could be extracted into a standalone Symfony bundle and
would still make sense without any specific business module installed.

---

## 3. Module system

### 3.1 ModuleInterface

Every module must implement `App\Core\Module\ModuleInterface`:

```php
interface ModuleInterface {
    public function getId(): string;          // 'crm', 'editorial', …
    public function getNavSections(): array;  // NavSection[]
    public function getPermissions(): array;  // NavPermission[]
}
```

Modules are registered in `config/services.yaml` with the tag `aurora.module`:

```yaml
App\Module\Crm\CrmModule:
    tags: [aurora.module]
```

### 3.2 ModuleRegistry

`App\Core\Module\ModuleRegistry` collects all tagged modules and:
- Filters nav items by required role (via AuthorizationCheckerInterface)
- Generates Symfony route paths (via UrlGeneratorInterface)
- Aggregates permissions from all modules

The Twig function `sidemenu_nav_sections()` calls the registry and returns the resolved
nav sections to the admin layout template.

### 3.3 Role & privilege system

Aurora uses **three flat roles** instead of a hierarchy:

| Role | Priority | Description |
|---|---|---|
| `ROLE_DEV` | 100 | Developer - bypasses **all** privilege checks |
| `ROLE_ADMIN` | 80 | Administrator - full access to all module features |
| `ROLE_USER` | 0 | Regular user - access limited to explicitly granted privileges |

Role hierarchy in `security.yaml`: `ROLE_DEV → ROLE_ADMIN → ROLE_USER`.

**Privileges** are fine-grained permission strings (e.g. `crm.contacts.view`) stored as a JSON
array on each `User` entity. Each module declares the privileges it owns via `NavPermission`:

```php
// In CrmModule::getPermissions()
new NavPermission('crm.contacts.view'),
new NavPermission('crm.contacts.create'),
new NavPermission('crm.contacts.delete'),
```

The custom `ModulePermissionVoter` resolves `#[IsGranted('crm.contacts.view')]` as:

1. `ROLE_DEV` → **always granted** (bypass)
2. `ROLE_ADMIN` → **always granted** (full access)
3. `ROLE_USER` → granted only if `user.privileges` contains the string

Assigning privileges to users is done via the Dev-only section in the user detail modal
(`/admin/users`) or programmatically.

**Sync command** - run after adding/removing module privileges to purge obsolete entries
and report new ones:

```bash
make sync-privileges    # php bin/console aurora:privileges:sync
```

This command is automatically called by `make install-dev`, `make deploy-prod`, and
`make aurora-update`.

---

## 4. Modules (`src/Module/<Name>/`)

Modules are organized **by domain first, by layer second**:

```
Module/<Name>/<Domain>/{Entity,Manager,Repository,Dto,Service,Serializer,Enum,Controller,…}
```

### 4.1 Module/Editorial

| Domain   | What it contains |
|----------|-----------------|
| Post     | Entities (Post, PostRevision, PostSlugHistory, PostTranslation, PostType, PostTypeField), Managers, Repository, DTO, Serializer, Enum, Services (BlocksRenderer, PostPageRenderer, PostTextExtractor), Voter, Messages + Handlers, Controllers |
| Comment  | Entities (Comment, CommentReaction), Manager + Decorator, Repository, Serializer, Enum, Service, Controllers, Contract |
| Form     | Entities (Form, FormField, FormFieldTranslation, FormSubmission, FormTranslation), Manager + Decorator, Repository, DTO, Serializer, Enum, Services, Controllers, Contract |
| Taxonomy | Entities (Taxonomy, TaxonomyTerm, translations), Manager, Repository, DTO, Serializer, Controller, Contract |
| Seo      | Services (AlternatesBuilder, RssFeedBuilder, SitemapBuilder) |
| Frontend | Controllers (HomeController, SitemapController) |

### 4.2 Module/Crm

| Domain  | What it contains |
|---------|-----------------|
| Contact | Entity, Repository, DTO (ContactInput), Serializer, Controller (backend CRUD + detail + activity timeline) |
| Company | Entity, Repository, DTO, Serializer, Controller (backend CRUD + detail) |
| Deal    | Entity, Repository, DTO, Serializer, Enum (DealStageEnum), Controller (backend CRUD + Kanban) |

### 4.3 Module/Erp

| Domain  | What it contains |
|---------|-----------------|
| Product | Entity (`reference` auto-generated via `SequenceGenerator`), Repository, DTO (ProductInput), Serializer, Enum (ProductStatusEnum, CurrencyEnum), Controller (backend CRUD) |

### 4.4 Module/Ecommerce

| Domain  | What it contains |
|---------|-----------------|
| Listing | Entity (FK to `Erp\Product`, `reference` auto-generated), Repository, DTO, Serializer, Manager, Controllers (backend CRUD + public Frontend) |
| Cart    | Entities (Cart, CartItem - `reference` auto-generated), Manager + Contract, Repository, Serializer, Controller |
| Order   | Entities (Order - `number` sequential via `SequenceGenerator`, OrderLine - `reference` auto-generated), Manager + Contract, Repository, Serializer, Enum (OrderStatusEnum), Services (OrderNotificationService, OrderRefundService), Payment (StripeService), Controllers (admin + front) |

### 4.5 Module/Billing

| Domain     | What it contains |
|------------|-----------------|
| Invoice    | Entity (internal `number` sequential, `supplierNumber` from OCR), InvoiceLine (`productCode` = OCR line code, `reference` = article ref), Tiers (supplier/client/partner/…), Manager + Contract, Repository, Serializer, Enum (InvoiceStatusEnum, TiersTypeEnum), Controller |
| Ocr        | Entity (OcrJob), Manager, Repository, Serializer, DTO (InvoiceDraft, InvoiceLineDraft), Service (OcrPipeline, InvoiceExtractor, DocTrClient, OllamaVisionClient, OcrDocumentRenderer), Message + Handler |
| Compliance | Service (SequenceChecker, ArchiveChecker, AuditChecker), Controller |

### 4.6 Module/Photo

| Domain  | What it contains |
|---------|-----------------|
| Gallery | Entities (Gallery, GalleryItem, GalleryInvite, GalleryFinalization, GalleryPick, GalleryItemComment - all with `reference`), Manager, Repository, Serializer, Enum, Services (GalleryWatermarkService, GalleryDownloadService, GalleryAccessService, GalleryNotificationService, GalleryPickService), Controllers (admin + front) |

### 4.7 Module/Ged

| Domain           | What it contains |
|------------------|-----------------|
| Document         | Entity, Manager, Repository, DTO, Serializer, Enum, Controller (backend CRUD) |
| DocumentCategory | Entity, Manager, Repository, DTO, Serializer, Controller (backend CRUD) |

### 4.8 Module/Hr

| Domain     | What it contains |
|------------|-----------------|
| Employee   | Entity (HrEmployee - employee record linked to a User), Manager, Repository, DTO, Serializer, Controller (backend CRUD). Syncs agency/service from User via `UserAgencyServiceUpdatingEvent`. |

### 4.9 Module/Planning

| Domain        | What it contains |
|---------------|-----------------|
| Planning      | Entity (Planning - container/calendar), Manager, Repository, DTO, Serializer, Controller (backend CRUD) |
| PlanningEvent | Entity (PlanningEvent - individual event in a planning), Manager, Repository, DTO, Serializer, Controller |

### 4.10 Module/Project

| Domain      | What it contains |
|-------------|-----------------|
| Project     | Entities (Project, ProjectColumn, ProjectLabel, ProjectSprint, ProjectSavedView), Manager, Repository, DTO, Serializer, Enum, Controller (backend CRUD + Kanban) |
| ProjectTask | Entities (ProjectTask, ProjectTaskComment, ProjectTaskItem, ProjectTaskTimeEntry), Manager, Repository, DTO, Serializer, Enum, Controller |

---

## 5. Conventions

### 5.1 PHP namespaces

```
App\Core\<Domain>\<Layer>\<ClassName>               -- e.g. App\Core\User\Entity\User
App\Module\<Name>\<Domain>\<Layer>\<ClassName>      -- e.g. App\Module\Crm\Contact\Entity\Contact
```

### 5.2 Templates

| Location | Twig namespace | Example |
|---|---|---|
| `src/Core/templates/Core/admin/` | `@Core` | `extends '@Core/admin/layout.html.twig'` |
| `src/Module/Editorial/templates/admin/` | `@Editorial` | `include '@Editorial/admin/posts/index.html.twig'` |
| `src/Module/Crm/templates/admin/` | `@Crm` | `include '@Crm/admin/contacts/index.html.twig'` |
| `templates/shared/` | `@Shared` | `include '@Shared/components/icon.html.twig'` |

Configured in `config/packages/twig.yaml`. Add one entry per module.

### 5.3 Assets / Vue components

```
src/Core/assets/admin/<C>.vue                → vue_component('core/admin/<C>')
src/Module/Editorial/assets/admin/<C>.vue    → vue_component('editorial/admin/<C>')
src/Module/Crm/assets/admin/<C>.vue          → vue_component('crm/admin/<C>')
```

Il n'y a pas de dossier `vue/` dans aurora-core - les controllers vivent directement sous
`admin/`, `front/` etc. Le dossier `vue/` existe uniquement dans campus (projet legacy/V2).

`src/Core/assets/app.js` merges one glob per module. Add a new glob + key-replace when adding a module.

Vite aliases:

| Alias        | Path                         |
|--------------|------------------------------|
| `@`          | `src/Core/assets/`         |
| `@core`      | `src/Core/assets/`               |
| `@editorial` | `src/Module/Editorial/assets/`   |
| `@crm`       | `src/Module/Crm/assets/`         |
| `@erp`       | `src/Module/Erp/assets/`         |
| `@ged`       | `src/Module/Ged/assets/`         |
| `@hr`        | `src/Module/Hr/assets/`          |
| `@planning`  | `src/Module/Planning/assets/`    |
| `@shared`    | `src/Core/assets/shared/`             |

### 5.4 Routes

Declared via `#[Route]` attributes. Auto-discovered via `services.yaml` resource `'../src/'`.

### 5.5 Doctrine

One mapping per module in `config/packages/doctrine.yaml`:

```yaml
mappings:
    AppCore:      { dir: src/Core,               prefix: 'App\Core' }
    AppEditorial: { dir: src/Module/Editorial,   prefix: 'App\Module\Editorial' }
    AppCrm:       { dir: src/Module/Crm,         prefix: 'App\Module\Crm' }
    AppErp:       { dir: src/Module/Erp,         prefix: 'App\Module\Erp' }
```

Add one mapping block per new module.

### 5.6 Client extensions (`AURORA_CLIENT_DIR`)

Aurora can be installed as a Composer package in a **client project** (`aurora-client`).
Client projects can add their own Vue components without modifying vendor code:

```
CLIENT_DIR/
  assets/
    client/
      Module/
        Tracking/
          admin/
            ProjectsApp.vue
```

Set the environment variable before running Vite:

```bash
AURORA_CLIENT_DIR=./assets/client pnpm --dir=vendor/aurora run dev
```

In `vite.config.js` (aurora-core), `AURORA_CLIENT_DIR` is mapped to the `@client` alias.
`src/Core/assets/app.js` scans `@client/Module/**/*.vue` and registers the components with the same
naming convention as first-party modules:

```
@client/Module/Tracking/admin/ProjectsApp.vue  →  vue_component('tracking/admin/ProjectsApp')
```

The client's `services.yaml` must also register its module class with `aurora.module` and
configure the `DumpJsTranslationsCommand` to include the client's translation dirs.

See the client `Makefile` variables `CLIENT_ASSETS` and `AURORA_ENV` for how this is wired.

---

### 5.7 Adding a new module (checklist)

1. Create `src/Module/<Name>/` with domain subfolders
2. Implement `<Name>Module.php` (ModuleInterface) - declare nav + permissions
3. Tag it `aurora.module` in `config/services.yaml`
4. Add Doctrine mapping to `config/packages/doctrine.yaml`
5. Add Twig namespace to `config/packages/twig.yaml`
6. Add Vue glob + alias to `src/Core/assets/app.js` + `vite.config.js` + `vitest.config.js`
7. Create templates under `src/Module/<Name>/templates/admin/` (co-located with the module's PHP code, like `assets/` and `translations/`)
8. Create Vue controllers under `src/Module/<Name>/assets/admin/` (or `front/`), co-localisés avec leurs composables dans `admin/{feature}/composables/`
9. Create `src/Module/<Name>/translations/messages.{fr,en,es,de}.yaml` and register the path in `config/packages/translation.yaml` (`framework.translator.paths`); add to `SOURCE_DIRS` in `DumpJsTranslationsCommand` so the keys also flow to vue-i18n
10. Add Vue-only labels (form fields, editor blocks…) under `src/Core/assets/locales/source/{locale}.js`
11. Add validator messages to `src/Core/translations/validators.*.yaml` if needed (or to the module's own file)
12. Generate + run Doctrine migration
13. **Sequences**: for each new entity, add `#[ORM\GeneratedValue(strategy: 'SEQUENCE')]` + `#[ORM\SequenceGenerator(sequenceName: 'seq_core_{entity}_id')]`; for business sequential references (human-readable `reference` field), add a `SequencePrefixEnum` case + `ApplicationParameterEnum` case (group `sequences`) - `SequenceGenerator` stores counters in `app_sequence_counters` table (rows created automatically on first use, no extra setup); run `make sync-params`

---

## 6. Deferred decisions

### 6.1 Shared primitives extraction

`Contact` lives in `Module/Crm/`. If a future module (e.g. Editorial's "author as CRM
contact") needs the same entity, extract it to `src/Shared/<Domain>/` at that point.
Do not create a Shared primitive speculatively.

### 6.2 Translation key split (`admin.editorial.*`)

Existing Editorial keys stay flat (`admin.posts.*`, `admin.taxonomies.*`, `admin.postTypes.*`).
New modules name their keys `admin.<module>.*` from the start (already the case for CRM:
`admin.crm.deals.*`, ERP: `admin.erp.products.*`, Ecommerce: `admin.ecommerce.listings.*`).
Front keys are namespaced by feature, not module: `front.shop.*`, `front.cart.*`, etc.

### 6.3 Front-end theming per module

`templates/front/themes/<slug>/` is kept flat. ThemeResolver expects a single path per
theme. Splitting requires making ThemeResolver multi-path aware - do this when a module
needs its own front-end templates.

### 6.4 Backend-only vs front-facing modules

Each module is **backend-only by default**. A module opts into a public front by adding
a `Module/<Name>/Frontend/Controller/` directory with public routes (typically prefixed
`/{locale}/<resource>`). Conventions:

| Module     | Front? | Why |
|------------|--------|-----|
| Editorial  | ✅ Yes | A CMS exists to serve public pages |
| Ecommerce  | ✅ Yes | Catalog (`/shop`), cart, checkout, order confirmation |
| Photo      | ✅ Yes | Client gallery pages (`/g/{slug}`) - password-protected |
| Ged        | ✅ Yes | Published documents exposed at `/{locale}/ged` |
| CRM        | ❌ Never | Contacts/companies/deals are private business data |
| ERP        | ❌ Internal-only | Inventory stays backend; the public catalog lives in Ecommerce |
| Billing    | ❌ Internal-only | Invoice management, suppliers, OCR - admin only |
| Hr         | ❌ Internal-only | Employee records - admin only |
| Planning   | ❌ Internal-only | Internal planning/agenda - admin only |
| Project    | ❌ Internal-only | Project & task management - admin only |
| Core       | n/a | Infrastructure |

**Frontend controller conventions:**

Every frontend controller must call `ViewBuilder::baseView(string $locale)` and merge
its own variables into the result. This injects `locale`, `context`, `themeContext`,
`pageDescription` and `alternates` - required by the default layout.

```php
return $this->render($this->themeResolver->resolve('...'), $this->viewBuilder->baseView($locale) + [
    'myVar' => $myVar,
]);
```

The `showFrontMenus` parameter (default `false`) controls whether the header and footer
load the Editorial nav menus (`primary`, `account`, `footer`). Pass `true` only for
modules that are part of the main site navigation (Editorial, Ecommerce). Standalone
modules like GED or Photo keep it `false` - they have their own layout or simply no
global nav.

### 6.5 Module dependencies & shared entities

Allowed direction of dependencies (import only downward):

```
Ecommerce  →  Erp          (catalog reads inventory)
Ecommerce  →  CRM          (Customer ↔ Contact link)
Billing    →  CRM          (Tiers can be linked to Company)
Editorial  →  (none)       - independent
CRM        →  (none)       - independent
ERP        →  (none)       - independent of business modules
Photo      →  (none)       - independent
All        →  Core
```

Modules **must not** depend upward (e.g. ERP must not import Ecommerce). When two
modules need the same concept, the lower module owns the canonical entity:

- `Erp\Product` is the source of truth for inventory (reference, cost, stock, suppliers).
- `Ecommerce\Listing` (planned) references an `Erp\Product` and adds shop-only fields:
  slug, marketing description, gallery, `isVisibleOnShop`, public price, SEO. ERP
  products that aren't sold online simply have no `Listing`.

### 6.7 Translations: YAML is the source of truth, split per module

Each module owns its translations:

| Owner | Path |
|---|---|
| Core | `src/Core/translations/{messages,validators,security}.{locale}.yaml` |
| Billing | `src/Module/Billing/translations/messages.{locale}.yaml` |
| CRM | `src/Module/Crm/translations/messages.{locale}.yaml` |
| Ecommerce | `src/Module/Ecommerce/translations/messages.{locale}.yaml` |
| Editorial | `src/Module/Editorial/translations/messages.{locale}.yaml` |
| ERP | `src/Module/Erp/translations/messages.{locale}.yaml` |
| Ged | `src/Module/Ged/translations/messages.{locale}.yaml` |
| Photo | `src/Module/Photo/translations/messages.{locale}.yaml` |
| Hr | `src/Module/Hr/translations/messages.{locale}.yaml` |
| Planning | `src/Module/Planning/translations/messages.{locale}.yaml` |
| Project | `src/Module/Project/translations/messages.{locale}.yaml` |

Symfony's translator merges all paths automatically - keys can share top-level prefixes
(e.g. `admin.nav.*` is partially defined by every module that contributes a sidemenu entry).
Paths are registered in `config/packages/translation.yaml` (`framework.translator.paths`).

**Two consumers**: Twig/PHP (Symfony's translator) and vue-i18n (Vue components). To avoid
duplicating shared keys, vue-i18n receives a deep-merge of two sources (`src/Core/assets/i18n.js`):

1. `src/Core/assets/locales/source/{locale}.js` - manual content for **Vue-only** keys (admin form labels,
   editor block labels, client-side validation messages…). Edit by hand.
2. `src/Core/assets/locales/generated/{locale}.json` - generated from the per-module YAMLs via
   `php bin/console app:translations:dump-js`. **Gitignored.** Auto-rebuilt by `pnpm dev` /
   `pnpm build` (npm `predev` / `prebuild` hooks). YAML wins on conflict.

The dump command iterates `SOURCE_DIRS` (the same list as `framework.translator.paths`),
deep-merges each module's YAML, then converts Symfony's `%var%` placeholders to vue-i18n's
`{var}` syntax automatically - write naturally in YAML.

**Convention**: a key used in **both** Twig and Vue lives in YAML. A key used **only** in Vue
stays in the JS source. Never duplicate the same key in both files.

### 6.8 Sequences and business references

Two distinct mechanisms coexist - never confuse them:

| Family | Mechanism | Example | Owner |
|---|---|---|---|
| Entity PKs | PostgreSQL sequence `seq_core_<entity>_id` | `seq_core_invoice_id` | Doctrine migrations |
| Business references | Table `app_sequence_counters` | row `(prefix='FAC', year=2026)` | `SequenceGenerator` |

All entity PKs use Doctrine's `SEQUENCE` strategy with explicit named sequences (`seq_core_<entity>_id`).
This makes sequences visible and manageable in PostgreSQL - no silent `IDENTITY` columns.

All business entities also carry a human-readable `reference` field (e.g. `FAC-2026-0001`,
`ORD-000001`) generated atomically via `Core\Sequence\SequenceGenerator::next()` or
`nextYearly()`. Prefixes are configurable in **Settings → Séquences** (`ApplicationParameterEnum`
cases with group `sequences`). Canonical defaults live in `SequencePrefixEnum`.

Business references are backed by the **`app_sequence_counters`** table, fully managed by
Doctrine migrations - no PostgreSQL sequences, no `schema_filter` needed.

```
Schema: app_sequence_counters(prefix VARCHAR(30), year INT, last_value INT)
Primary key: (prefix, year)

year = 0    → global sequence   → next('LOG')          → LOG-000032
year = YYYY → yearly sequence   → nextYearly('FAC', 2026) → FAC-2026-0001
```

Increment is atomic via PostgreSQL upsert: `INSERT … ON CONFLICT DO UPDATE RETURNING`.
A new prefix row is created automatically on first use - no manual setup required.
To inspect current values: `SELECT * FROM app_sequence_counters ORDER BY prefix, year;`

After data imports or fixture loads, run:

```bash
make sync-sequences   # resets all seq_core_*_id to MAX(id)+1 (entity PKs only)
```

### 6.6 Entity naming: no module prefix on class names

PHP namespaces are the prefix. Keep entity class names clean:

```
App\Module\Ecommerce\Order\Entity\Order        ✅
App\Module\Ecommerce\Order\Entity\EcommerceOrder ❌  (verbose, redundant)
```

**Doctrine table names**, however, are always module-prefixed to avoid SQL collisions
and clarify queries in logs:

```
crm_contacts, crm_companies, crm_deals
erp_products
ecommerce_listings, ecommerce_orders, ecommerce_carts, ecommerce_customers
```

---

## 7. Roadmap

> Historical record. A `[x]` means the work was done - not that the module
> still lives in this repository; most were extracted in July 2026, and
> Editorial is being rebuilt here as a native module.

- [x] Module manifest + ModuleRegistry + dynamic admin sidemenu
- [x] Module/Editorial - full editorial CMS (posts, taxonomies, comments, forms, SEO, sitemap)
- [x] Module/Crm - Contact, Company, Deal (CRUD + Kanban)
- [x] Permission registry (ModulePermissionVoter + per-module `#[IsGranted]`)
- [x] Audit log / Activity timeline (Core - cross-module action logging, dev viewer)
- [x] Module/Erp - Product entity (inventory backend, backend CRUD)
- [x] Module/Ecommerce - Listing, Cart, Order, Payment (Stripe), public Frontend
- [x] Module/Billing - Invoice management, Tiers (supplier/client/…), OCR pipeline (docTR + Ollama VLM), compliance
- [x] Module/Photo - Client gallery delivery (galleries, items, invites, picks, watermarking)
- [x] Module/Ged - Document management (documents, categories)
- [x] Module/Hr - Human resources, employee records (HrEmployee + User link, agency/service sync via domain events)
- [x] Module/Planning - Planning & agenda (plannings, events)
- [x] Module/Project - Project & task management (projects, tasks, sprints, kanban, time tracking)
- [x] Core/Sequence - Named PostgreSQL sequences for all PKs + configurable business reference numbers
- [x] Core/Media - Module-scoped upload dirs (`media/`, `ocr/`, `users/`, `photo/`) with `%app.upload_dir%`
- [x] Auth: simplified 3-role system (User/Admin/Dev) + per-user fine-grained privileges
- [x] Privileges UI - Dev can assign module privileges to users from the user detail modal
- [x] `aurora:privileges:sync` - purges obsolete privilege strings after module changes
- [x] Client extension system - `AURORA_CLIENT_DIR` + `@client` alias for custom Vue modules in client projects
- [ ] Editor.js block: ProductGrid (Editorial → Ecommerce, embed listings in posts/pages)
- [ ] ThemeResolver multi-path (per-module front templates)
