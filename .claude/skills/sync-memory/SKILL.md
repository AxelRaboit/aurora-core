---
name: sync-memory
description: Run the Aurora memory-hygiene cycle and sync .claude/memory/ → ~/.claude/projects/.../memory/ via `make sync-claude-memory`. Use when the user asks to "sync memory", "synchroniser la mémoire", "save what we learned", finalize a non-trivial task (refacto, new feature, architectural decision), or whenever new/modified memory files exist that haven't been pushed to the global memory yet. Enforces the 6-step hygiene loop documented in CLAUDE.md §"Règle d'hygiène mémoire".
scope: core-only
---

# sync-memory

Wraps `make sync-claude-memory` with the **mandatory hygiene loop** from
CLAUDE.md. Just running the rsync isn't the goal - the goal is to keep the
versioned memory (`/.claude/memory/`) coherent before pushing it to the
per-user `~/.claude/projects/.../memory/`.

## The 6-step cycle (run them in order)

### 1. Detect what changed

Run `git status .claude/memory/` and `git diff --stat .claude/memory/`.
List the added/modified/deleted memory files. If nothing changed AND the
user did not explicitly ask to sync, ask whether they want to force a sync
anyway (e.g., after pulling someone else's memory changes).

### 2. Read the affected memories

For each modified or newly added file, read the full content (not just the
title in `MEMORY.md`). For deletions, infer from the removed file's path
what the memory was about so you can sanity-check the deletion was
intentional.

### 3. Freshness check (CRITICAL - most common source of stale memory)

For every memory that asserts the existence of a file, class, flag, command,
or directory:

- File paths → check the file exists.
- Class / function / interface names → `grep -r "class <Name>"` or
  `grep -r "function <name>"`.
- DI attributes / flags → grep for the literal string.

If any reference is stale (file moved, class renamed, flag removed):
- **Either update the memory** to point to the new state, **or remove it**
  if the underlying convention disappeared.
- Do NOT push a sync that propagates stale info.

Report each stale reference to the user with the fix you applied.

### 4. Placement check

Aurora has 3 memory scopes - verify each new/modified file is in the right
subdir (see `.claude/memory/MEMORY.md` for the rule):

- `aurora-core/` - convention/decision/piège **propre au bundle core**
  (PHP/Symfony backend de aurora-core, ne s'applique pas aux clients).
- `aurora-shared/` - convention transversale qui concerne **tout dev**
  (Vue, HTTP, JS, i18n, process, naming) - **lue à la fois par aurora-core
  et par les projets clients** via `vendor/axelraboit/aurora/`.
- `aurora-client/` - pattern d'extension **côté consommateur uniquement**
  (Sylius-style extends, `App\AuroraBundle::$resolve_target_entities`, etc.).

Misplaced file → move it (`git mv`) and update the line in the relevant
`MEMORY.md` index.

### 5. Index integrity

For each subdir's `MEMORY.md`:
- Every `.md` file in the subdir (except `MEMORY.md` itself) has a line in
  the index.
- Every line in the index points to an existing file.
- No duplicate entries.

Fix any drift before syncing.

### 6. Run the sync

```bash
make sync-claude-memory
```

Report the output (the Makefile prints `✅ N fichiers mémoire + M fichiers
docs synchronisés → <path>`).

## Final report to the user

A concise summary:

```
Mémoires modifiées : 3 (+2 ajout, -0 suppression)
- aurora-core/feedback_<topic>.md  [updated freshness]
- aurora-shared/convention_<topic>.md  [moved from aurora-core/]
- aurora-client/pattern_<topic>.md  [new]

Fresh-check : 1 référence obsolète corrigée
  - aurora-core/decision_xxx.md : `OldClassName` → `NewClassName` (renommée commit abc1234)

Sync : ✅ 28 fichiers mémoire + 47 fichiers docs synchronisés
```

If nothing changed → just say so and offer to sync anyway if the user
suspects the dest is stale.

## Boundaries

- **Never sync without the hygiene loop.** A blind rsync propagates stale
  memory to the next session - that's exactly what the loop prevents.
- **Never delete a memory just because its referenced file moved** - first
  try to repoint it; deletion is for when the underlying convention is
  truly gone.
- **Never write new memories opportunistically here.** This skill is a
  curator, not an author. If the user discovers a new convention worth
  saving mid-sync, save it as a normal memory write (the auto-memory
  instructions cover the format), then run the sync as a separate step.
- **Read-only on `~/.claude/projects/.../memory/`** - only the Makefile
  rsync writes to it. Never edit those files directly.
