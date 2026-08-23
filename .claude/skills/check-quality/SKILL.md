---
name: check-quality
description: Full post-coding review of the current change - runs the mechanical gate (`make ft`: formatters, phpstan, phpunit, vitest, build, migration check), then judges the diff against Aurora's written conventions in `.claude/memory/`: layering into Controller/Manager/Service/Repository, DRY, SOLID, single responsibility, naming, cross-module dependencies, test coverage and test honesty, plus the docs/memory audit owed before committing. Use after finishing a feature or fix, or when the user says "check", "vérifie", "relis mon code", "c'est propre ?", before `ship`.
scope: core-only
---

# check-quality

Review what was just coded, before it becomes a commit.

Two kinds of check, and they are not interchangeable:

- **Mechanical** - `make ft`. Binary, non-negotiable, no judgment involved.
- **Judgment** - layering, duplication, responsibility, naming, whether the
  tests actually prove anything. A green `make ft` says nothing about these.

Run the mechanical gate first: `make fix` rewrites files (formatters, rector),
so judging the code before it runs means judging code that is about to change.

## Scope

Review **what changed**, not the repository. Establish the scope first:

```bash
git status --short
git diff --stat
git diff
```

If the work is already committed but unpushed, widen to `git diff origin/develop...HEAD`.

Read the **whole file** for anything the diff touches. Layering and duplication
are invisible in a hunk - a controller doing repository work looks fine in
isolation and wrong in context.

## Step 1 - Mechanical gate

```bash
make ft
```

`ft` = `fix` → `test` → `build` → `migrate-check`, which covers:

| Stage | What it catches |
|---|---|
| `make translation` | JS translation JSON out of sync with the YAML |
| `fix-js` / `fix-twig` / `fix-rector` / `fix-php` | style, autofixable patterns |
| `make stan` | phpstan - **where refactor regressions surface** |
| `make test` | vitest + phpunit |
| `make build` | the Vite bundle actually compiles |
| `make migrate-check` | dev DB has pending migrations |

Use `make ftl` (no asset build) only when no Vue/CSS/JS file changed.

**No escape hatch** - `process_make_ft_before_commit.md` is explicit: no
`--no-verify`, no `@phpstan-ignore` added to get through, no phpstan baseline.
If a case genuinely forces one, it gets a code comment explaining why *and* a
memory entry.

Anything red: fix it, re-run, and keep going until green. Report what was
broken and how it was fixed - do not quietly absorb it.

For a large refactor, run the gate **per unit of work**, not once at the end.
The convention exists because a rollout done without regular `make stan`
accumulated 100 phpstan errors before anyone looked.

## Step 2 - Layering and responsibility

The heart of the review. For each changed file, ask where the logic *belongs*:

- **Controller** - parse the request, delegate, render. A controller holding
  business rules, building queries, or reaching into the entity manager is a
  finding. It should read as a dispatcher.
- **Manager** - write-side domain operations, entity lifecycle, audit hooks.
- **Service** - cohesive domain logic that is not entity CRUD. One reason to
  change; a service whose name contains "and", or whose methods share no state,
  is doing two jobs.
- **Repository** - queries. No business decisions.
- **DTO / Input** - shape and validate incoming data.
- **Serializer** - entity → JSON for the front.
- **View builder** - assemble what a template needs.

Judge against the domain index for the layer touched, opened from
`.claude/memory/aurora-core/MEMORY.md`:

- PHP / Symfony / Doctrine → `backend/MEMORY.md`
- Vue admin → `vue-backend/MEMORY.md`
- Vue / Twig public site → `vue-frontend/MEMORY.md`
- Cross-cutting Vue / JS → `vue-transversal/MEMORY.md`
- Cross-module patterns and decisions → `architecture/MEMORY.md`

Open the actual convention file - the index summary is not enough to judge
against, as its own usage rules say.

### Cross-module dependencies

Check every new `use` statement crossing a module boundary against
`.claude/memory/aurora-shared/convention_no_cross_module_dep.md`. A module
reaching sideways into another is a finding even when it compiles - prefer
reading a shared parameter, or an interface in a neutral module, over a direct
dependency on a module that may not be installed.

## Step 3 - DRY, SOLID, single responsibility

- **Duplication that matters.** Two copies of a rule that must change together
  is a finding; two lines that merely look alike is not. Before flagging, grep
  for the pattern elsewhere - if it already exists in three places, the finding
  is "extract", not "you added one".
- **Single responsibility.** One reason to change, per class and per method.
- **Open/closed.** In this codebase that mostly means the extensibility layers.
  For an entity with backend CRUD, do not re-derive the rules here - invoke
  **`check-extensibility`**, which owns that audit. Same for module toggles:
  **`audit-module-toggles`**.
- **Dependency inversion.** Constructors type-hint interfaces, not concrete
  classes, wherever the extensibility convention provides one.
- **Liskov / variance.** phpstan catches most of it; trust step 1 here.

Judgment call worth stating plainly: an abstraction introduced for a single
caller is usually worse than the duplication it removes. Say so rather than
demanding symmetry.

## Step 4 - Conventions in force

Check the changed code against the written rules. All of these are real files
under `.claude/memory/aurora-shared/`:

| Area | File |
|---|---|
| Naming (classes, files, routes, tables) | `convention_naming.md` |
| `final` vs `readonly` on services | `convention_service_final_vs_readonly.md` |
| Cross-module dependencies | `convention_no_cross_module_dep.md` |
| Route naming, edit vs update | `convention_edit_vs_update_route_naming.md` |
| i18n key casing | `convention_i18n_key_casing.md` |
| Domain exception → translation key | `convention_domain_exception_translation_key.md` |
| Vue SFC kept thin | `convention_sfc_thin_presentation.md` |
| No raw `fetch` | `convention_no_raw_fetch.md` |
| No `var` in JS | `convention_js_no_var.md` |
| JS privacy | `convention_js_privacy.md` |
| Form components | `convention_form_components.md` |
| Modals and confirmation | `convention_modal_and_confirmation.md` |
| Storage under `var/uploads` | `convention_storage_var_uploads.md` |

Only open the ones the diff actually touches. Quote the rule when reporting a
breach, so the finding is checkable rather than an opinion.

## Step 5 - Tests

Two questions, and the second is the one that matters.

**Does the change have tests?** New behaviour, fixed bug, or new branch in the
logic → there should be a test. Suites live at:

- `tests/Unit/{Core,Dto,Entity,Enum,Locale,Manager,Module,Serializer}`
- `tests/Integration/{Concern,Controller,Manager,Module,Service,Translation}`
- `tests/e2e` (Playwright, `make test-e2e`)

```bash
make test-backend-unit
make test-backend-integration
make test-frontend
```

**Would the test have failed before the fix?** A test that passes against the
defect it targets is worse than no test - it certifies the bug. This repo has
been burned by exactly that: commit `110ef850` records three successive test
versions that all passed against the bug they aimed at, because `loginUser()`
pre-filled the token storage, a CLI-rendered 404 never reaches Twig, and
nothing running inside aurora-core can observe what a consumer project sees.

So: for a bug fix, **re-introduce the defect mentally (or actually) and confirm
the test goes red**. If it would not, say so - an honest "this test does not
prove the fix" is a finding, not a nitpick.

Also check the test asserts behaviour rather than implementation, and that
fixtures/factories follow the existing shape rather than inventing a new one.

## Step 6 - Docs and memory audit

Owed **before** the commit, per `process_doc_audit_before_commit.md`, and it
runs both ways.

```bash
git diff --name-only
grep -rn "<ChangedClass>\|<changedMethod>\|<config_key>" docs/ .claude/memory/
```

Triggers that force the audit: a renamed class/interface/public method, a
changed signature, a moved namespace, a new recurring pattern, or a
renamed/deleted doc.

- Code changed → verify every doc and memory describing it is still true, and
  update it **in the same commit**.
- New convention or pattern established → it earns a memory file. Placement:
  core-only → `.claude/memory/aurora-core/<domain>/`; any developer writing
  code → `aurora-shared/`; client extension only → `aurora-client/`. Format is
  `## Règle` → `## Pourquoi` → `## Comment l'appliquer`, plus a line in the
  domain index, then `make sync-claude-memory`.

Do not silently rewrite memory as part of this review - propose the change and
let the user confirm.

## Output

A compact markdown report, matching the house style of `check-extensibility`:
✅ / ❌ / ⚠️ per line, grouped by step, ending in a verdict.

```
# Quality check - <short description of the change>

## Scope
4 files, 2 modules - Platform/Auth, Editorial/Menu

## 1. Mechanical gate
✅ make ft green (phpstan 0, 1864 tests, build OK)
⚠️ migrate-check: 1 pending migration on the dev DB

## 2. Layering
✅ Controller delegates, no query building
❌ FooService also sends the notification - two reasons to change

## 3. DRY / SOLID
…

## 4. Conventions
…

## 5. Tests
❌ No test for the disabled path; the bug would not be caught by the suite

## 6. Docs & memory
⚠️ docs/aurora-core/dev/menus.md still documents the old behaviour

## Verdict
2 ❌ / 2 ⚠️ - not ready to ship. Fix the ❌ first.
```

Close with an actionable fix list: one line per ❌, quoting `file:line` and the
exact change needed. Rank by severity - something that will break at runtime
outranks a naming slip.

State the verdict plainly. "Ready to ship" only when the mechanical gate is
green and no ❌ remains; otherwise say what blocks it. Do not soften a red
result, and do not pad a clean one with invented nitpicks.

## Boundaries

- **Mechanical fixes are applied** - that is what `make fix` does, and the
  result is reported.
- **Judgment findings are proposed, not applied.** Report first; refactor only
  once the user picks what to act on. Silently restructuring code during a
  review is how a review turns into an unrequested rewrite.
- **Don't audit the whole repo.** Scope is the current change. Something ugly
  but untouched nearby is a note at most, or a separate task.
- **Don't commit.** `ship` owns that, and it re-checks the gate.
- **Delegate rather than duplicate**: `check-extensibility` for entity layers,
  `audit-module-toggles` for module toggles, `sync-memory` for memory hygiene.
