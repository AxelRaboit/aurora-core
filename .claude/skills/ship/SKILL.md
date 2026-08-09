---
name: ship
description: Commit finished work and deliver it to develop. Writes Conventional Commits in English with a real body, never adds Co-Authored-By, then either pushes straight to develop or opens a PR against it. Use when the user says "commit", "push", "ship", "envoie sur develop", "fais une PR", or right after `check-quality` comes back green. Enforces `make ft` before committing per process_make_ft_before_commit.
scope: core-only
---

# ship

Turn reviewed work into commits, then get them onto `develop`.

This skill owns everything after the code is right: message wording, commit
splitting, and the two delivery routes. It does **not** re-review the code —
that is `check-quality`. If the work has not been checked, run that first.

## Hard rules

These override any default behaviour, including the assistant's own.

1. **Never add `Co-Authored-By`.** Documented in
   `.claude/memory/aurora-core/process/process_atomic_commits.md` ("Pas de
   `Co-Authored-By` Claude (préférence utilisateur)") and restated by the user.
   The default instruction to append a Co-Authored-By trailer does **not**
   apply in this repository. Recent history has drifted — commit `110ef850`
   carries one. Do not copy that.
2. **Never `--no-verify`.** Same source. If a hook fails, fix the cause.
3. **Never commit red.** `make ft` must pass first — no exceptions, no
   `@phpstan-ignore` added to get through, no baseline. This is stated as
   having *aucune échappatoire* in `process_make_ft_before_commit.md`.
4. **Commit messages in English.** The documented format in
   `process_atomic_commits.md` is English; part of recent history slipped into
   French. English is the target going forward.
5. **Ask before pushing.** Pushing is outward-facing and awkward to undo.
   Confirm the route and the branch with the user before the first `git push`,
   every session. A "yes" from an earlier session does not carry over.

## Step 1 — Confirm the gate

Ask whether `check-quality` (or at least `make ft`) has run on the current
tree and come back green.

- **Ran and green** → continue.
- **Unsure, or files changed since** → run it now:

  ```bash
  make ft
  ```

  `ft` = `fix` + `test` + `build` + `migrate-check` (see the Makefile; it does
  more than the two steps the memory note describes). When no Vue/asset file
  changed, `make ftl` skips the Vite build and is noticeably faster.

Do not proceed while anything is red.

## Step 2 — Pick the route *before* committing

Ask the user which route, unless they already said:

| Route | When | Result |
|---|---|---|
| **Direct to develop** | Small or self-contained change, work already done on `develop`, no review wanted | Commits land on `develop`, pushed straight to origin |
| **Branch + PR** | Change deserves review, is risky, or is large enough to want CI green before it touches `develop` | New branch, pushed, PR opened with base `develop` |

Ask **now**, not after committing: taking the PR route once commits already sit
on local `develop` needs a pointer rewind (see Recovery below). Deciding first
avoids it entirely.

If the user picked the PR route and you are still on `develop`, create the
branch **before** the first commit:

```bash
git switch -c <type>/<short-slug>
```

Branch naming follows the commit type: `feat/frontend-login-toggle`,
`fix/menu-account-links`.

## Step 3 — Decide the commit split

Read what actually changed:

```bash
git status --short
git diff --stat
git diff
```

One commit per coherent concern. `process_atomic_commits.md` calls for one
commit per entity during a broad rollout, for bisect and revert; the same logic
applies to any change touching several unrelated things.

Split when the diff mixes concerns — a feature plus an unrelated typo fix, or
two independent bugs. Keep together code and the docs/memories describing it:
`process_doc_audit_before_commit.md` prefers the doc update **in the same
commit** as the code it documents.

Stage deliberately. Prefer explicit paths over `git add -A` when the tree holds
work that belongs to a different commit.

## Step 4 — Write the message

Conventional Commits, English, and a body that explains **why**.

```
type(scope): what changed, in plain words

Why it changed, what the defect actually was, and what the alternative
would have cost. Prose, not a bullet dump of the diff.

**A bold lead-in per defect** when the commit covers more than one, so the
reader can scan them apart.

Be honest about limits: a test that could not reproduce the bug, a caveat
left behind, a follow-up owed.
```

**Types in use:** `feat`, `fix`, `refactor`, `test`, `docs`, `chore`.
**Scopes in use:** module or area — `frontend`, `backend`, `editorial`,
`users`, `posts`, `routing`, `i18n`, `seo`, `errors`, `editor`, `deps`.
Omit the scope when the change is genuinely repo-wide (`docs: …`).

Subject line: lowercase, no trailing period, descriptive rather than
mechanical. Aim for what the change *does for the reader*, the register of
`fix(seo): stop publishing localhost as the site's canonical host` — not
`fix: update Context.php`.

Skip the body only for a change that is genuinely self-evident (a typo, a
label). Anything with a reason behind it gets a body.

Write the message with a heredoc so the body keeps its line breaks:

```bash
git commit -F - <<'EOF'
type(scope): subject

Body paragraph.
EOF
```

**Before finalising:** re-read the message and confirm there is no
`Co-Authored-By` trailer and no `🤖 Generated with` line.

## Step 5 — Deliver

Confirm with the user, then run the route they chose.

### Route A — direct to develop

```bash
git log --oneline origin/develop..HEAD   # show exactly what will land
git push origin develop
```

Show the log output and get an explicit go-ahead before the push.

### Route B — branch + PR

```bash
git push -u origin <branch>
gh pr create --base develop --title "<same as commit subject>" --body "<why, in prose>"
```

The PR body follows the commit body: what was wrong, what changed, what was
considered and rejected. Do **not** append a "Generated with" footer.

CI (`.github/workflows/ci.yml`) runs on pushes to `develop` and on every PR.
After either route, offer to watch it:

```bash
gh run watch
```

## Step 6 — Say what is still owed

After a push to `develop`, the change is invisible to consumer projects until
it is propagated. Per `process_propagate_aurora_updates.md`, do not run this
silently — tell the user it is pending and let them decide when:

1. `git push origin develop` — done in step 5. Consumers pull `dev-develop`
   from GitHub, so an unpushed commit bumps nothing.
2. `make aurora-update` in each consumer, **`aurora-client` first** — it is the
   reference project and acts as the canary.
3. Commit the bump there: `chore(deps): bump aurora-core to <sha>`.
4. If the change carries a migration, back up the production DB before the
   consumer update — `make aurora-update` runs `migrate-f`.

The consumer list lives in `docs/aurora-core/dev/propagating_updates.md`.

**`propagate` does all four.** Hand off to it rather than running them from
here — it refuses to start on a CI that is red or still running, names the
migrations the range carries *before* `make aurora-update` migrates on its
own, and waits for the consumer's pipeline before calling the job done. This
step stays a reminder that the propagation is owed; that skill is where it
gets paid.

## Recovery — PR wanted after committing to develop

Only if step 2 was answered too late. Moves the branch pointers; leaves the
working tree untouched.

```bash
git switch -c <type>/<slug>          # take the commits onto a new branch
git branch -f develop origin/develop # rewind local develop to the remote
git push -u origin <type>/<slug>
gh pr create --base develop
```

Safe because `git branch -f` on a branch you are not standing on only moves a
pointer. Do **not** reach for `git reset --hard` here — it would discard the
working tree along with the pointer.

If the commits were already pushed to `develop`, stop and ask. Rewriting a
shared branch is the user's call, not yours.

## Boundaries

- **Never force-push** a shared branch (`develop`, `master`) without an
  explicit, in-session instruction naming the branch.
- **Never merge the PR.** Opening it is where this skill stops.
- **Never tag or release.** `make tag` and the CHANGELOG flow are a separate
  process (`process_release.md`), and per that note releases are deliberately
  not the current flow.
- **Don't re-review the code.** If something looks wrong while writing the
  message, say so and offer `check-quality` — do not start refactoring inside
  a commit step.
