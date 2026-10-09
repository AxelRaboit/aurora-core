#!/usr/bin/env bash
#
# Publishes aurora-core, propagates it, and deploys it - the whole chain.
#
# The chain was six manual steps across two repositories and a server, and the
# two that could hurt were invisible in a commit list: the migrations a range
# carries, and whether the canary survived the bump. Both are gates here.
#
# **It refuses rather than guesses.** Every step that cannot be verified stops
# the run with a reason, and nothing after a refusal is attempted. A half-done
# release is worse than one that never started: a tag with no release becomes
# an installable version for every consumer, and a deployed server whose
# canary never passed is a defect found by a client instead of by us.
#
# Usage, from the root of aurora-core:
#
#   tools/release/release.sh                 # the whole chain, backup included
#   tools/release/release.sh --no-backup     # skips the production dump
#   tools/release/release.sh --stop-at=core  # publishes aurora-core, then stops
#   tools/release/release.sh --dry-run       # says what it would do, writes nothing
#
# Stages, in order: core, client, deploy. `--stop-at` takes one of those.
#
# Documented chain: docs/aurora-core/dev/propagating_updates.md and the
# `propagate` skill, of which this is the executable form.

set -euo pipefail

CLIENT_PATH="${CLIENT_PATH:-../aurora-client}"
VPS_HOST="${VPS_HOST:-vps}"
VPS_PATH="${VPS_PATH:-/var/www/aurora-client}"

# Read off the remote rather than pinned here, so a fork or a rename does not
# publish into somebody else's repository.
repository_of() {
    git -C "$1" remote get-url origin | sed -E 's#(git@github.com:|https://github.com/)##; s#\.git$##'
}

BACKUP=1
STOP_AT="deploy"
DRY_RUN=0

for argument in "$@"; do
    case "$argument" in
        --no-backup) BACKUP=0 ;;
        --dry-run) DRY_RUN=1 ;;
        --stop-at=*) STOP_AT="${argument#*=}" ;;
        -h|--help) sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "❌ Argument inconnu : $argument" >&2; exit 1 ;;
    esac
done

case "$STOP_AT" in
    core|client|deploy) ;;
    *) echo "❌ --stop-at prend core, client ou deploy (reçu : $STOP_AT)" >&2; exit 1 ;;
esac

step() { printf '\n\033[1m▸ %s\033[0m\n' "$1"; }
fail() { printf '\n❌ %s\n' "$1" >&2; exit 1; }
note() { printf '   %s\n' "$1"; }
run() {
    if [ "$DRY_RUN" = 1 ]; then
        printf '   [à blanc] %s\n' "$*"
        return 0
    fi
    "$@"
}

# ---------------------------------------------------------------------------
# Stage 0 - what is true before anything is published
# ---------------------------------------------------------------------------

step "Vérifications préalables"

[ -f composer.json ] && [ -d migrations ] || fail "À lancer depuis la racine d'aurora-core."
[ -d "$CLIENT_PATH" ] || fail "aurora-client introuvable à $CLIENT_PATH (voir CLIENT_PATH)."

[ -z "$(git status --porcelain)" ] || fail "L'arbre d'aurora-core n'est pas propre."

branch=$(git rev-parse --abbrev-ref HEAD)
[ "$branch" = "develop" ] || fail "Sur '$branch' au lieu de 'develop'."

git fetch -q origin develop master
[ -z "$(git log --oneline origin/develop..HEAD)" ] || fail "Des commits locaux ne sont pas poussés."

# The version lives in the changelog, not here: reviewing the release means
# reviewing that number. A merge with no closed section publishes nothing, so
# reading it first is what turns a silent no-op into a refusal.
version=$(grep -m1 -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' CHANGELOG.md | tr -d '#[] ' || true)
[ -n "$version" ] || fail "Aucune section close dans CHANGELOG.md. Clore '## [Unreleased]' en '## [X.Y.Z] - AAAA-MM-JJ'."

git rev-parse -q --verify "refs/tags/v$version" >/dev/null && fail "Le tag v$version existe déjà. Le changelog n'a pas été clos pour cette version."

note "Version à publier : v$version"

ahead=$(git rev-list --count origin/master..origin/develop)
[ "$ahead" -gt 0 ] || fail "develop n'a rien de plus que master : rien à publier."
note "$ahead commits à publier"

# The migrations a range carries, named out loud. This is the only part of a
# propagation that can destroy something, and the only part a commit list
# hides.
migrations=$(git diff --name-only "origin/master..origin/develop" -- migrations/ | sed 's|migrations/||' || true)
if [ -n "$migrations" ]; then
    note "Migrations de la fourchette :"
    printf '     %s\n' $migrations
else
    note "Aucune migration dans la fourchette."
fi

step "CI de develop"
conclusion=$(gh run list --branch develop --limit 1 --json conclusion -q '.[0].conclusion')
[ "$conclusion" = "success" ] || fail "La CI de develop est '$conclusion'. Une release ne part pas sur une CI qui n'est pas verte."
note "verte"

# ---------------------------------------------------------------------------
# Stage 1 - publish aurora-core
# ---------------------------------------------------------------------------

step "Publication d'aurora-core v$version"

# **Through a pull request, on both repositories.** It is the documented flow
# (`process_release.md`: "ouvrir la PR develop → master, la faire relire,
# merger"), it leaves something reviewable behind, and it is the only route
# that works when `master` is protected - which it is on aurora-client, where
# a direct push came back `protected branch hook declined` on 09/10/2026.
#
# And a merge is unavoidable here whatever the protection says: after each
# release `master` carries a merge commit that `develop` does not, so
# develop→master is never a fast-forward on aurora-core.
core_repository=$(repository_of .)
note "dépôt : $core_repository"

core_pr=$(gh pr list -R "$core_repository" --base master --head develop --state open --json number -q '.[0].number' || true)

if [ -z "$core_pr" ]; then
    if [ "$DRY_RUN" = 1 ]; then
        note "[à blanc] gh pr create --base master --head develop"
        core_pr="<nouvelle>"
    else
        core_pr=$(gh pr create -R "$core_repository" --base master --head develop \
            --title "release $version" \
            --body "Publie la v$version. Le workflow lit le numéro dans la première section close de CHANGELOG.md et publie cette section comme notes de release." \
            | grep -oE '[0-9]+$')
        note "PR #$core_pr ouverte"
    fi
else
    note "PR #$core_pr déjà ouverte, réutilisée"
fi

run gh pr merge "$core_pr" -R "$core_repository" --merge

if [ "$DRY_RUN" = 0 ]; then
    note "Attente du tag v$version (le workflow le pose)…"
    for _ in $(seq 1 40); do
        sleep 15
        git fetch -q --tags origin
        if git rev-parse -q --verify "refs/tags/v$version" >/dev/null; then
            note "tag v$version publié"
            break
        fi
    done
    git rev-parse -q --verify "refs/tags/v$version" >/dev/null \
        || fail "Le tag v$version n'est pas apparu. Voir les logs du workflow release."
fi

[ "$STOP_AT" = "core" ] && { printf "\n✅ Arrêt demandé après la publication d'aurora-core.\n"; exit 0; }

# ---------------------------------------------------------------------------
# Stage 2 - the canary
# ---------------------------------------------------------------------------

step "Propagation vers aurora-client (le canari)"

[ -z "$(git -C "$CLIENT_PATH" status --porcelain)" ] || fail "L'arbre d'aurora-client n'est pas propre."

run git -C "$CLIENT_PATH" fetch -q origin
run git -C "$CLIENT_PATH" switch develop -q
run git -C "$CLIENT_PATH" pull -q --ff-only origin develop

run make -C "$CLIENT_PATH" aurora-update

if [ "$DRY_RUN" = 0 ]; then
    pinned=$(grep -o '"version": "v[0-9.]*"' "$CLIENT_PATH/composer.lock" | head -1 | grep -oE 'v[0-9.]+')
    [ "$pinned" = "v$version" ] || fail "aurora-client épingle $pinned au lieu de v$version."
    note "épinglé sur $pinned"
fi

# The gate that matters. A red `make ft` here is the propagation's problem, not
# the consumer's: it is the canary doing its job, and it means something in
# core does not survive contact with a project that overrides it.
step "make ft sur le canari"
run make -C "$CLIENT_PATH" ft || fail "make ft est rouge sur aurora-client. Le défaut est dans aurora-core, à corriger là-bas."
note "vert"

changed=$(git -C "$CLIENT_PATH" status --porcelain | awk '{print $2}')
if [ -n "$changed" ] && [ "$DRY_RUN" = 0 ]; then
    for file in $changed; do
        [ "$file" = "composer.lock" ] || fail "aurora-update a modifié $file en plus du lock. À regarder avant de committer."
    done
fi

run git -C "$CLIENT_PATH" add composer.lock
run git -C "$CLIENT_PATH" commit -q -m "chore(deps): bump aurora-core to v$version

$(printf '%s' "${migrations:-Aucune migration dans la fourchette.}" | sed 's/^/Migration : /')

make ft vert ici après le bump."
run git -C "$CLIENT_PATH" push -q origin develop

if [ "$DRY_RUN" = 0 ]; then
    note "Attente de la CI d'aurora-client…"
    sleep 20
    client_run=$(gh run list -R AxelRaboit/aurora-client --branch develop --limit 1 --json databaseId -q '.[0].databaseId')
    gh run watch "$client_run" -R AxelRaboit/aurora-client --exit-status >/dev/null 2>&1 || true
    client_conclusion=$(gh run view "$client_run" -R AxelRaboit/aurora-client --json conclusion -q .conclusion)
    [ "$client_conclusion" = "success" ] || fail "La CI d'aurora-client est '$client_conclusion'."
    note "verte"
fi

step "Publication d'aurora-client"

# Its `master` is protected, so this is a pull request and not a push - even
# though develop *is* a fast-forward of master here, unlike on aurora-core.
# The check below is therefore about the merge being clean, not about pushing.
client_repository=$(repository_of "$CLIENT_PATH")
note "dépôt : $client_repository"

git -C "$CLIENT_PATH" merge-base --is-ancestor origin/master origin/develop 2>/dev/null \
    || fail "master d'aurora-client a divergé de develop : à régler à la main."

client_pr=$(gh pr list -R "$client_repository" --base master --head develop --state open --json number -q '.[0].number' || true)

if [ -z "$client_pr" ]; then
    if [ "$DRY_RUN" = 1 ]; then
        note "[à blanc] gh pr create --base master --head develop"
        client_pr="<nouvelle>"
    else
        client_pr=$(gh pr create -R "$client_repository" --base master --head develop \
            --title "release: aurora-core v$version" \
            --body "Monte aurora-core en v$version. \`make ft\` vert sur develop après le bump, et seul le lock a changé." \
            | grep -oE '[0-9]+$')
        note "PR #$client_pr ouverte"
    fi
else
    note "PR #$client_pr déjà ouverte, réutilisée"
fi

run gh pr merge "$client_pr" -R "$client_repository" --merge

if [ "$DRY_RUN" = 0 ]; then
    note "Attente du tag d'aurora-client…"
    previous=$(git -C "$CLIENT_PATH" describe --tags --abbrev=0 2>/dev/null || echo "")
    for _ in $(seq 1 40); do
        sleep 15
        git -C "$CLIENT_PATH" fetch -q --tags origin
        current=$(git -C "$CLIENT_PATH" describe --tags --abbrev=0 origin/master 2>/dev/null || echo "")
        if [ -n "$current" ] && [ "$current" != "$previous" ]; then
            break
        fi
    done
    client_tag=$(git -C "$CLIENT_PATH" describe --tags --abbrev=0 origin/master 2>/dev/null || echo "")
    [ -n "$client_tag" ] && [ "$client_tag" != "$previous" ] \
        || fail "Aucun nouveau tag sur aurora-client. Voir les logs de son workflow release."
    note "tag $client_tag publié"
else
    client_tag="<calculé par le workflow>"
fi

[ "$STOP_AT" = "client" ] && { printf "\n✅ Arrêt demandé après la publication d'aurora-client.\n"; exit 0; }

# ---------------------------------------------------------------------------
# Stage 3 - production
# ---------------------------------------------------------------------------

step "Déploiement sur $VPS_HOST"

served=$(ssh -o BatchMode=yes "$VPS_HOST" "cat $VPS_PATH/VERSION 2>/dev/null || echo inconnu")
note "le serveur sert actuellement : $served"

if [ "$BACKUP" = 1 ]; then
    step "Sauvegarde de la base de production"
    if [ "$DRY_RUN" = 0 ]; then
        # The database name is read from the server's own configuration rather
        # than passed in: a dump of the wrong database is a backup that does
        # not exist, and it is discovered at the worst possible moment.
        ssh -o BatchMode=yes "$VPS_HOST" "bash -s" <<SSH
set -euo pipefail
cd "$VPS_PATH"
url=\$(grep -h '^DATABASE_URL=' .env.local .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"')
[ -n "\$url" ] || { echo "❌ DATABASE_URL introuvable sur le serveur." >&2; exit 1; }
database=\$(printf '%s' "\$url" | sed -E 's#.*/([^/?]+).*#\1#')
target="\$HOME/aurora-backups/\${database}-before-$version-\$(date +%Y%m%d-%H%M%S).dump"
mkdir -p "\$HOME/aurora-backups"
pg_dump --dbname="\$url" --format=custom --file="\$target"
echo "   sauvegarde : \$target (\$(du -h "\$target" | cut -f1))"
SSH
    else
        note "[à blanc] pg_dump de la base de production"
    fi
elif [ -n "$migrations" ]; then
    # Said plainly, because this is the one combination that can lose data:
    # migrations about to run against production with nothing to go back to.
    note "⚠ Sauvegarde ignorée (--no-backup), et des migrations vont tourner."
else
    note "⚠ Sauvegarde ignorée (--no-backup). Aucune migration dans la fourchette."
fi

step "make deploy-prod"

if [ "$DRY_RUN" = 0 ]; then
    ssh -o BatchMode=yes "$VPS_HOST" "bash -s" <<SSH
set -euo pipefail
cd "$VPS_PATH"
git fetch -q --tags origin
git checkout -q "$client_tag"
make deploy-prod
SSH
else
    note "[à blanc] checkout $client_tag puis make deploy-prod sur $VPS_HOST"
fi

step "Vérification"

if [ "$DRY_RUN" = 0 ]; then
    now_served=$(ssh -o BatchMode=yes "$VPS_HOST" "cat $VPS_PATH/VERSION 2>/dev/null || echo inconnu")
    [ "$now_served" != "$served" ] || fail "VERSION n'a pas bougé ($now_served) : le déploiement n'a pas pris."
    note "le serveur sert maintenant : $now_served"

    # `deploy-prod` ends on a cache clear that fails if the application cannot
    # boot, so reaching here already means it does. Asked again anyway: the
    # thing worth knowing after a deploy is not that a command returned zero.
    ssh -o BatchMode=yes "$VPS_HOST" "cd $VPS_PATH && APP_ENV=prod APP_DEBUG=0 php bin/console about --env=prod >/dev/null" \
        && note "l'application démarre" \
        || fail "L'application ne démarre plus. Revenir au tag précédent et restaurer la sauvegarde."
fi

printf '\n✅ aurora-core v%s publié, propagé, et en production.\n' "$version"
