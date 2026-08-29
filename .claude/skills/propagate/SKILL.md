---
name: propagate
description: Carry a pushed aurora-core change into the consumer projects. Refuses to start on a red or unfinished CI, names the migrations the bump carries before running anything, bumps aurora-client first as the canary, and waits for the consumer's own CI before calling it done. Use when the user says "propage", "propager", "bump le client", "mets aurora-client à jour", or right after `ship` reports the propagation is owed.
scope: core-only
---

# propagate

Get a pushed aurora-core commit into the projects that consume it.

`ship` stops at the push, on purpose: it says the propagation is owed and
leaves the timing to the user. This skill starts exactly there.

## The one thing that can hurt

A bump is mostly boring. `make aurora-update` runs `migrate-f` on the
consumer, so **a range carrying a migration is the only part that can destroy
something**, and it is the part that is invisible in a commit list.

Establish it before running anything, and say it out loud (step 2). Everything
else in this file is bookkeeping by comparison.

## Step 0 - Know what you are propagating

```bash
cd <aurora-core>
git log --oneline origin/develop -1        # the sha consumers will pull
git status --short                          # must be clean
```

Consumers pull **published releases** (`^0.6`), not a branch. **An unreleased
commit bumps nothing**: pushing `develop` is necessary and no longer sufficient,
since `master` is what gets tagged. **An unpushed commit bumps nothing either**,
if `git log origin/develop..HEAD` is not empty, the work is not propagatable
yet; `ship` owns getting it there.

## Step 1 - Refuse a red or unfinished CI

Bumping a sha whose pipeline has not passed propagates red into the consumer,
where it will look like the consumer's fault.

```bash
gh run list --branch develop --limit 1
gh run watch <id> --exit-status    # if it is still running, wait for it
gh run view <id> --json conclusion -q '.conclusion'
```

- `success` → continue.
- `failure` → stop. Report it; the fix belongs in aurora-core, not here.
- still running → wait. Do not start the bump "while it finishes".

## Step 2 - Name the range, and its migrations

Find what the consumer currently pins, and list what it is about to take:

```bash
cd <consumer>
grep -o 'aurora-core/zipball/[a-f0-9]*' composer.lock | head -1   # current sha

cd <aurora-core>
git log --oneline <current-sha>..origin/develop
git diff --name-only <current-sha>..origin/develop -- migrations/
```

**Report both to the user before going further.** If the migrations list is
not empty:

- say so plainly, name each migration and what it does;
- for a **production** consumer, the database backup happens *before*
  `make aurora-update`, not after - the command migrates on its own;
- check whether any of them is irreversible (an empty or lossy `down()`), and
  say which. `Version20260809170000` - the one that drops the plain block
  column - is the worked example: reversible in schema, not in content.

A range with no migration needs no ceremony. Say "no migration in the range"
and move on; that sentence is what makes its absence a fact rather than an
assumption.

## Step 3 - aurora-client first, always

`aurora-client` is the reference project and the canary. If it breaks, stop
before touching anything with real data in it.

```bash
cd ../aurora-client
make aurora-update
make ft
```

- `make aurora-update` bumps to the latest `develop` and runs `migrate-f`.
- `make ft` is the gate - treat a red here as **the propagation's** problem,
  not the consumer's. It is the canary doing its job: something in core does
  not survive contact with a project that overrides it.

Do not use `make pull-update` here: it installs from the lock and bumps
nothing. `make pull-and-bump` is the combo when the consumer also has team
commits to pull first.

## Step 4 - Commit the bump

Only `composer.lock` should have changed. If anything else did, look at it
before committing - a bump that rewrites project files is a bump doing
something it was not asked to.

```bash
git status --short
git add composer.lock
```

The message says what the consumer is getting, in the consumer's terms - not
a copy of the core commit subjects:

```
chore(deps): bump aurora-core to <sha>

What the range brings, in a sentence or three, from the point of view of
someone using the product rather than someone who wrote it.

**Carries a migration.** <name> does <what>. Back up before running this
against production.        ← only when true; otherwise say "No migration in
                             the range."

`make ft` green here after the bump.
```

Then push and **wait for the consumer's own CI**:

```bash
git push origin develop
gh run list --branch develop --limit 1
gh run watch <id> --exit-status
```

A propagation is not finished when the bump is pushed. It is finished when the
consumer's pipeline is green.

## Step 5 - The other consumers

The list lives in `docs/aurora-core/dev/propagating_updates.md`. Today it holds
aurora-client alone; anything added there gets steps 3 and 4, in the same
order, after the canary is green.

## Boundaries

- **Don't commit in aurora-core.** `ship` owns that. If the core is not
  pushed, this skill has nothing to do yet.
- **Don't fix core defects from the consumer.** A red `make ft` after a bump
  is a finding to carry back, not something to patch locally - a fix applied
  in the consumer is a fix the next project will not get.
- **Don't skip the canary** because a change "only touches Twig". The
  weekend of 2026-08-09 propagated eleven times, and the client caught the
  featured-media rename, a theme ignoring the grid, `setBlocks()` in fixtures
  and a non-extensible `DocumentCategory` - none of which failed in core.
- **Don't touch production databases.** Say what needs backing up and let the
  user do it.

Voir aussi : `ship` (ce qui précède), `process_propagate_aurora_updates.md`
et `docs/aurora-core/dev/propagating_updates.md` (la procédure de référence,
dont ce skill est la forme exécutable).
