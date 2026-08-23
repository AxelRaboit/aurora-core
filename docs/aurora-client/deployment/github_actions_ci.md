# GitHub Actions CI - setup d'un projet aurora-client

Le template aurora-client embarque un workflow GitHub Actions
(`.github/workflows/ci.yml`) qui exécute, à chaque push sur les branches
long-lived (`master`, `develop`) et sur chaque PR :

- `composer validate` + `composer audit`
- Build des assets Vite via `make build`
- Linters : `make lint-php`, `make lint-twig`, `make lint-js`, `make rector`
- Static analysis : `make stan` (PHPStan)
- Setup DB de test (schema:create from entity metadata + mark all
  migrations applied - workaround multi-namespace, cf. §2 ci-dessous)
- Tests : `make test-frontend` + `make test-backend-unit`

Le workflow utilise un **PostgreSQL 18** en service GitHub Actions, **PHP
8.4**, **Node 24** et **pnpm 10** (matrix à 1 entrée).

**aurora-core étant un repo public**, aucun setup supplémentaire n'est
requis : `composer install` clone le vendor sans authentification, le
workflow tourne directement.

---

## 1. Setup initial - rien à faire 🎉

1. Cloner aurora-client (ou ton fork) pour démarrer un projet
2. Pousser sur GitHub
3. Premier push → la CI démarre automatiquement

Branches déclencheuses configurées par défaut : `master`, `develop`,
toutes les PRs. À adapter dans le `on:` block du workflow si ton projet
utilise `main` au lieu de `master`.

---

## 2. Setup DB de test - pourquoi `schema:create` au lieu de `migrations:migrate`

Le workflow CI initialise la DB de test via :

```yaml
- name: Create schema from entity metadata
  run: php bin/console doctrine:schema:create --env=test

- name: Initialize migration metadata + mark all applied
  run: |
    php bin/console doctrine:migrations:sync-metadata-storage --env=test --no-interaction
    php bin/console doctrine:migrations:version --add --all --no-interaction --env=test
```

**Pourquoi pas `doctrine:migrations:migrate` directement ?**

Aurora-client utilise deux namespaces de migrations :

- `DoctrineMigrations` - celles du vendor `axelraboit/aurora`
- `ClientMigrations` - celles du projet client (sous `migrations/`)

Sur une DB fresh, Doctrine Migrations 3.x **ne mélange pas
strictement par version timestamp** à travers les namespaces - il
traite chaque namespace dans son ordre de déclaration. Quand une
migration `ClientMigrations\Version20260508123924` (par exemple, une
extension de table Aurora) tente d'ALTER une table créée par
`DoctrineMigrations\Version20260508122957` (version timestamp **plus
petite** donc qui devrait passer avant), Doctrine plante car la table
n'existe pas encore.

`schema:create` génère le schéma actuel depuis les annotations
Doctrine des entités (vendor + client), puis on marque toutes les
migrations comme déjà appliquées. Résultat équivalent en moins
d'opérations, et **sans dépendre de l'ordre de migrations**.

> ⚠️ Cette approche fonctionne **uniquement** parce que les migrations
> du projet sont purement structurelles (CREATE / ALTER / RENAME) - pas
> de migrations de données (data backfills). Si vous ajoutez une
> migration qui insère / convertit des données via SQL, le CI
> *raterait* ce backfill. Dans ce cas, exécuter manuellement la
> migration de données dans une étape CI séparée, OU réécrire la
> donnée via les fixtures.

> En **runtime quotidien dev / prod**, `make migrate-f` (qui fait
> `doctrine:migrations:migrate`) marche normalement - vous n'ajoutez
> qu'**une seule** migration neuve à la fois (un namespace), pas un
> mélange historique entier.

---

## 3. Modifier le workflow CI

Le fichier `.github/workflows/ci.yml` est **owned par le projet
client** - pas synchronisé par `make aurora-update`. Libre à toi de :

- Ajouter une matrice (multi-PHP, multi-PostgreSQL)
- Ajouter des steps de déploiement post-tests (push Docker image, deploy
  Kubernetes, etc.)
- Ajouter des Integration tests (`make test-backend-integration`) une
  fois que tu en as
- Désactiver le workflow temporairement (commenter le bloc `on:`)

---

## 4. Pièges connus

- **Cache pnpm/composer périmé** : la clé de cache contient le hash
  du lockfile correspondant - un changement de lockfile invalide le
  cache automatiquement. Si tu suspectes un cache corrompu, supprime
  les caches via *Actions → Caches → Delete*.
- **Workflow trigger sur `main` vs `master`** : aurora-client par
  convention utilise `master + develop`. Si ton projet utilise
  `main + develop`, modifier `on.push.branches` en haut du fichier.

---

## 5. Variants - autres CI providers

Le workflow GitHub Actions est le seul fourni dans le template. Pour
GitLab CI / Bitbucket Pipelines / Jenkins, recréer la même séquence
d'étapes :

```
composer install
pnpm install (vendor aurora + client root)
make build
make lint-php lint-twig lint-js rector stan
schema:create + migrations:version --add --all
make test-frontend test-backend-unit
```

---

## Annexe - si vous forkez aurora-core et le rendez privé

Le `GITHUB_TOKEN` auto-fourni par GitHub Actions sur le repo client
n'a **pas** accès à d'autres repos privés du même propriétaire (frontière
de sécurité par défaut). Si vous décidez de forker aurora-core et de
garder le fork privé :

### Setup en 2 étapes

#### Étape A - Créer un fine-grained PAT

1. Aller sur https://github.com/settings/personal-access-tokens/new
2. **Token name** : ex. `aurora-core read-only for CI of <projet>`
3. **Expiration** : 90 jours (à renouveler) ou + selon ta tolérance
4. **Repository access** → *Only select repositories* → cocher
   `<owner>/aurora-core` (ton fork privé)
5. **Repository permissions** :
   - **Contents** → `Read-only`
6. Cliquer *Generate token* et **copier la valeur** immédiatement

#### Étape B - Ajouter le PAT en repo secret

Sur le repo client :

1. *Settings → Secrets and variables → Actions → New repository secret*
2. **Name** : `AURORA_CORE_READ_TOKEN`
3. **Value** : coller le PAT

#### Étape C - Réactiver l'étape composer auth dans le workflow

Avant `Install PHP dependencies`, ajouter :

```yaml
- name: Configure composer GitHub auth for aurora-core
  run: composer config --global --auth github-oauth.github.com ${{ secrets.AURORA_CORE_READ_TOKEN }}
```

Les PATs expirent. Pour renouveler : créer un nouveau PAT (même config),
puis *Settings → Secrets → AURORA_CORE_READ_TOKEN → Update*.
