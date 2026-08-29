# Convention - Propager une mise à jour d'aurora-core aux projets consommateurs

> **Règle dure** : dès qu'une modification d'aurora-core est mergée sur
> `develop`, il faut la **propager à tous les projets consommateurs**. Une
> feature livrée dans le bundle n'a aucun effet tant que les projets clients
> n'ont pas bumpé.

> **À tenir à jour** : ce document liste les projets consommateurs. **Ajouter
> une ligne au tableau ci-dessous à chaque nouveau projet** qui consomme
> `axelraboit/aurora`.

---

## Comment les clients consomment aurora-core (aujourd'hui)

Chaque projet consommateur pointe sur une **version publiée** d'aurora-core,
via le dépôt VCS GitHub (composer) :

```json
// composer.json du projet consommateur
"require": { "axelraboit/aurora": "^0.6" },
"repositories": [
  { "type": "vcs", "url": "git@github.com:AxelRaboit/aurora-core.git" }
]
```

**Conséquence importante** : `composer update axelraboit/aurora` ne voit que
les **tags publiés**. Un commit poussé sur `develop` ne change rien chez les
clients tant qu'il n'est pas passé sur `master` et publié en release. C'est le
but : un client reste sur une version pendant qu'il prend le temps de migrer,
au lieu de recevoir chaque commit au moment où il tombe.

## Le chemin d'un commit jusqu'à un client

```
develop  ──PR──▶  master  ──push──▶  workflow Release  ──▶  tag vX.Y.Z + release
                                                                    │
                                                       make aurora-update
                                                                    ▼
                                                            projet client
```

1. Le travail se fait sur `develop`, comme avant. Rien n'y est visible des
   clients.
2. Une pull request `develop` → `master` **clôt `## [Unreleased]`** du
   `CHANGELOG.md` en `## [X.Y.Z] - AAAA-MM-JJ`. C'est le seul geste manuel, et
   il se relit dans la PR.
3. Le merge déclenche `.github/workflows/release.yml` : il lit ce numéro,
   crée le tag `vX.Y.Z` et publie la release avec la section du changelog en
   corps. Merger sans avoir clos la section ne publie rien et le dit dans les
   logs.
4. Le client lance `make aurora-update`, qui prend la dernière version
   compatible avec sa contrainte.

> **Pourquoi la release et pas `dev-develop`** : la section « Dans
> aurora-client » du changelog liste ce qui ne se synchronise pas tout seul.
> Elle n'a de sens que si le client peut se situer entre deux points fixes.
> Avec un flux roulant, il n'y a pas de « entre sa version et la cible » : il
> y a juste le dernier commit, et la liste des choses à faire à la main se
> perd.

---

## Projets consommateurs

| Projet | Chemin local | Rôle | Notes |
|---|---|---|---|
| **aurora-client** | `../aurora-client/` | **Projet modèle / référence** | Gabarit canonique de tout projet consommateur. **À mettre à jour en premier** (canari). Reste minimal et propre : c'est lui qui valide le `client_template`. Anciennement `aurora-myspace`, renommé en août 2026 ; l'ancien dépôt est conservé en lecture seule sous `aurora-client-backup`. |

> _Ajouter ici tout nouveau projet consommant `axelraboit/aurora`._

### Pourquoi aurora-client est le « projet modèle »

- Les nouveaux projets consommateurs sont scaffoldés à partir du template
  livré par aurora-core dans [`.claude/client_template/`](../../../.claude/client_template/)
  (CLAUDE.md, Makefile, README.md) - synchronisé via `make sync-claude-md`.
- aurora-client est l'instance de référence de ce template : on le garde
  **volontairement épuré** (pas de logique métier lourde) pour qu'il serve de
  gabarit clair. Quand on doute de « comment un client doit faire X », c'est
  la référence.
- C'est donc le **premier** à bumper lors d'une propagation : s'il casse, on
  arrête avant de toucher les clients métier.

---

## Procédure de propagation

À faire pour **chaque** projet du tableau, après tout changement aurora-core.

### 1. Pousser aurora-core

```bash
# depuis aurora-core
git push origin develop
```
Sans ça, `composer update` côté client tirerait l'ancien état (cf. plus haut).

### 2. Bumper chaque consommateur

```bash
# depuis aurora-client D'ABORD (projet modèle / canari), puis les autres consommateurs
make aurora-update
```

`aurora-update` enchaîne : `composer update axelraboit/aurora` → installs
(bundle + tools) → `pnpm install` → `cache:clear` → **`migrate-f`** (applique
les nouvelles migrations Doctrine) → sync privilèges → syncs
(jsconfig/env/readme/security/claude-md/makefile) → `translation` → `build`.

> ⚠️ `aurora-update` lance `migrate-f` : si le bump apporte une **migration**
> (nouvelle table/colonne), elle s'applique à la base du client. Faire un
> backup avant sur les bases de prod.

### 3. Vérifier

```bash
make ft        # fix + test + migrate-check (si le projet a des tests)
# ou au minimum : make test && make build
```
aurora-client n'a pas de suite de tests propre → la vérif est le `cache:clear`
(compilation du conteneur DI) + `build` réussis pendant `aurora-update`.

### 4. Commiter le bump

```bash
git add composer.lock   # + Makefile si make sync-makefile l'a modifié
git commit -m "chore(deps): bump aurora-core to <sha>"
```
Message standard : `chore(deps): bump aurora-core to <sha>` (+ résumé court
des features apportées). Préfixe `chore(deps):` (cf. historique des clients).

---

## `aurora-update` vs `pull-update` (ordre canonique)

| Commande | Quand |
|---|---|
| `make pull-update` | Après un `git pull` d'équipe : installe depuis le **lock** (respecte la version verrouillée), migre, cache, syncs. **Ne bump pas** aurora-core. |
| `make aurora-update` | Quand on veut **explicitement** une version plus récente d'aurora-core (bump sur le dernier `develop`). |
| `make pull-and-bump` | Combo dans le bon ordre : `pull-update` (sync au lock équipe) **puis** `aurora-update` (bump par-dessus). |

Un garde-fou (`_no-recent-aurora-update`) refuse `pull-update` juste après un
`aurora-update` récent (< 5 min) pour éviter d'écraser le bump.

---

## Le skill

`propagate` (`.claude/skills/propagate/`) est la forme exécutable de cette
page. Il ajoute deux refus que la checklist ci-dessous laisse à la vigilance :

- il ne démarre pas si la CI d'aurora-core est rouge **ou encore en cours** -
  bumper sur un sha non validé propage du rouge chez le consommateur, où il
  aura l'air d'être de sa faute ;
- il énonce les migrations du range **avant** de lancer `make aurora-update`,
  qui les applique tout seul via `migrate-f`.

Ce document reste la référence : la liste des consommateurs se tient ici.

---

## Checklist (copier à chaque propagation)

- [ ] `git push origin develop` (aurora-core)
- [ ] aurora-client : `make aurora-update` → `make ft` vert → commit bump
- [ ] _(répéter pour tout nouveau projet du tableau)_
- [ ] Backups DB faits si le bump contient une migration
