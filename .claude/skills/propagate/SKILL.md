---
name: propagate
description: Carry a pushed aurora-core change into the consumer projects. Refuses to start on a red or unfinished CI, names the migrations the bump carries before running anything, bumps aurora-client first as the canary, and waits for the consumer's own CI before calling it done. Use when the user says "propage", "propager", "bump le client", "mets aurora-client à jour", or right after `ship` reports the propagation is owed.
scope: core-only
---

# propagate

Get a pushed aurora-core commit into the projects that consume it, and onto
the server that serves them.

`ship` stops at the push, on purpose: it says the propagation is owed and
leaves the timing to the user. This skill starts exactly there.

## First: `make release` does all of it

Since 08/10/2026 the whole chain is one command in aurora-core -
`tools/release/release.sh`, wired as `make release`. It publishes aurora-core,
waits for the tag the workflow posts, bumps aurora-client, gates on `make ft`
there, publishes aurora-client, deploys the server, and checks that `VERSION`
moved and the application still boots.

```bash
make release DRY=1          # says what it would do, writes nothing
make release                # the chain, production dump included
make release NO_BACKUP=1    # skips the dump - a deliberate word, not a default
make release STOP_AT=core   # publishes aurora-core and stops
```

**Run `DRY=1` first, every time.** The refusals are the point: it stops with a
reason on a dirty tree, unpushed commits, an aurora-client `master` that has
diverged from `develop` (checked before anything is published), a CI that is
not green, a changelog
with no closed section, a tag that already exists, a lock bump that touched
more than the lock, a red canary, a tag that never appears, a `VERSION` that
does not move, or an application that no longer boots. Nothing after a refusal
is attempted. After each publication it brings `develop` back onto `master` in
both repositories, as a fast-forward only, so the next release does not stop on
a divergence.

**The steps below are still the reference.** They are what the script does, and
what you need to know how to redo by hand when it stops somewhere. Read them
before running it the first time; after that, the script is the way.

**Permissions.** Publishing needs `gh pr merge`, or `git merge` plus a push to
`master`. A session whose permission layer refuses those cannot release, and
routing the same command through the script to get past the refusal is not the
answer - say which gesture is blocked and let the user decide. Allow rules
added mid-session are not picked up by the running session. If `make release`
itself is refused while the individual git commands are allowed, walk the chain
in the open: a `make` target is opaque to a permission classifier, the commands
it runs are not.

## The two repositories release differently, and their numbers do not match

Measured on 09/10/2026, publishing aurora-core v4.1.0 all the way to the
server. Getting this wrong is how an evening goes sideways.

| | aurora-core | aurora-client |
|---|---|---|
| Where the version comes from | the topmost **closed section of `CHANGELOG.md`** | **computed from the commit messages** since the last tag; it has no changelog |
| What a `chore(deps):` bump produces | nothing on its own | a **patch** bump (`feat:` → minor, `type!:`/`BREAKING CHANGE` → major) |
| `master` | accepted a direct push of a merge commit | **protected**: a direct push is declined by a branch protection hook |
| develop → master | **never a fast-forward** - each release leaves a merge commit on `master` that `develop` lacks, so a merge is required | a fast-forward, but it still goes through a PR because of the protection |
| How to publish | PR develop → master, merge it | PR develop → master, merge it |

**The version numbers are not the same number.** aurora-core v4.1.0 published
aurora-client **v3.8.1**. They used to coincide - the client's tags read
v3.8.0, v3.7.1, v3.7.0 like core's - and they stopped the day core went to 4.0.
Do not infer one from the other, in either direction.

That matters most on the server: **`/var/www/aurora-client/VERSION` holds
aurora-client's tag, not aurora-core's.** To know which aurora-core production
is actually running:

```bash
ssh vps 'grep -o "\"version\": \"v[0-9.]*\"" /var/www/aurora-client/composer.lock | head -1'
```

Reading `VERSION` and comparing it to `gh release list` on aurora-core is how
you conclude production is up to date when it is two releases behind.

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

Consumers pull **published releases** (`^1.0`), not a branch. **An unreleased
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

## Step 6 - The server, which is the step everyone forgets

**A bumped lock is not a deployed application.** On 08/10/2026 the production
server was serving **v3.8.0** while v4.0.0 had been published that afternoon
and aurora-client's lock already pinned it: the bump had been pushed and never
deployed. A deploy at that point was not applying the two migrations of the
version in hand but **four**, and crossing a major on the way.

Nothing about that was visible without reading the server. So:

```bash
ssh vps 'cat /var/www/aurora-client/VERSION'   # before, and again after
```

**The server only ever deploys tags of aurora-client**, so publishing
aurora-core is not enough: the consumer has to be published too, through its
own PR (see the table above). That is the hop that gets skipped, and it is what
the lag above was.

Then, on the server:

```bash
ssh vps 'cd /var/www/aurora-client && git fetch -q --tags origin && git checkout -q <client tag>'
ssh vps 'cd /var/www/aurora-client && make deploy-prod'
```

`deploy-prod` refuses without an exact tag on `HEAD` - the checkout is detached
on purpose, and that refusal is what stops a deploy of whatever happened to be
checked out. It runs the migrations itself and ends on its own post-deploy
checks: deployed version, the application booting in prod, no pending
migration, the worker active, no failed message, and the site answering 200.

**Verify it yourself anyway**, because what is worth knowing after a deploy is
not that a command returned zero:

```bash
ssh vps 'cat /var/www/aurora-client/VERSION'   # must differ from before
ssh vps 'cd /var/www/aurora-client && php bin/console dbal:run-sql --env=prod "<a count>"'
```

A deploy that leaves `VERSION` unchanged did not happen; say so rather than
reporting success. And when checking a new column, get the table name from the
migration rather than from memory - `core_notes_spaces`, not
`core_note_spaces`, cost a false alarm on a deploy that had worked.

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
- **Back up production before migrations run, and say what you backed up.**
  `make release` dumps by default and `--no-backup` takes a deliberate word,
  because migrations running with nothing to go back to is the one combination
  that loses data. If the user waives the dump, that is their call - record
  that they waived it rather than staying silent about it.
- **Never restore, drop or rewrite a production database on your own.** A dump
  is additive and reversible; the rest is the user's decision.

Voir aussi : `ship` (ce qui précède), `process_propagate_aurora_updates.md`
et `docs/aurora-core/dev/propagating_updates.md` (la procédure de référence,
dont ce skill est la forme exécutable).
