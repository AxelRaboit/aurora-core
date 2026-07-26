#!/usr/bin/env bash
#
# Rollout: split each extracted module's subtree out of the aurora-core monorepo
# and push it to its own GitHub repo (one Composer package per repo).
#
# Aurora was simplified to Core + Editorial only (see git tag
# pre-simplify-editorial-only for the prior state). The other 11 modules
# (Tools, Crm, Billing, Photo, Project, Hr, Notes, PersonalFinance,
# Planning, Assistant, Ecommerce+Erp/aurora-commerce) were already split
# out to their own repos before this point and are intentionally frozen
# there — this script no longer re-publishes them. Their repos remain on
# GitHub as-is; re-integration means pulling from those repos directly,
# not from aurora-core.
#
# Prerequisites (do these FIRST):
#   1. Create the empty GitHub repo (no auto README/license) under the org:
#        aurora-editorial
#      (aurora-core IS this monorepo.) Pushed to the `master` branch.
#   2. Be on a clean `develop` (or the branch you publish from) with the
#      module's composer.json + config/services.php committed.
#
# Usage:
#   bin/split-modules.sh              # split + push aurora-editorial
#
# Idempotent: re-running force-pushes the same content, keeping full git
# history.
#
set -uo pipefail

ORG="git@github.com:AxelRaboit"
TARGET_BRANCH="master"

# repo-name => module dir (single-prefix modules)
SINGLE=(
    "aurora-editorial:src/Module/Editorial"
)

only="${1:-}"
ok=(); failed=()

split_single() {
    local repo="$1" prefix="$2" branch="split-$1"
    echo ">> $repo  ($prefix)"
    git branch -D "$branch" >/dev/null 2>&1 || true
    if ! git subtree split --prefix="$prefix" -b "$branch" >/dev/null 2>&1; then
        echo "   !! subtree split failed"; failed+=("$repo"); return
    fi
    if git push -f "${ORG}/${repo}.git" "${branch}:${TARGET_BRANCH}" 2>/dev/null; then
        echo "   ok -> ${ORG}/${repo}.git"; ok+=("$repo")
    else
        echo "   !! push failed (repo created? access?)"; failed+=("$repo")
    fi
    git branch -D "$branch" >/dev/null 2>&1 || true
}

for entry in "${SINGLE[@]}"; do
    repo="${entry%%:*}"; prefix="${entry##*:}"
    [ -n "$only" ] && [ "$only" != "$repo" ] && continue
    split_single "$repo" "$prefix"
done

echo
echo "Done. pushed: ${ok[*]:-none}"
[ "${#failed[@]}" -gt 0 ] && echo "FAILED: ${failed[*]}" && exit 1
exit 0
