# Démarrer sur un projet aurora-client existant - quickstart 10 min

Tu viens de `git clone` un projet client Aurora (aurora-welding,
aurora-client, autre fork) et tu veux le faire tourner en local. Ce doc
te guide étape par étape.

> 📘 Pour **créer un nouveau projet** à partir d'aurora-client (forker
> le template), voir [`setup.md`](setup.md) section "Démarrer un nouveau
> projet" - ce doc-ci suppose que le projet existe déjà et qu'on rejoint
> l'équipe.

---

## Prérequis

| Outil | Version min | Vérifier |
|---|---|---|
| PHP | 8.4 | `php -v` |
| Composer | 2.x | `composer --version` |
| PostgreSQL | 16+ | `psql --version` (en local - sinon Docker, cf. §3 B) |
| Node.js | 24+ | `node -v` |
| pnpm | 10+ | `pnpm --version` |
| Symfony CLI | latest | `symfony version` (pour `make start`) |

Manque quelque chose ? Checklist exhaustive : [`../../aurora-core/ops/prerequisites.md`](../../aurora-core/ops/prerequisites.md).

---

## 1. Installer les dépendances

```bash
composer install                                # vendor PHP (aurora-core + Symfony deps)
pnpm install                                    # deps JS du client (Vue, axios, modules métier…)
(cd vendor/axelraboit/aurora && pnpm install)   # tooling Vite/Vitest dans le vendor
```

> Pourquoi 2× `pnpm install` ? Le client a son `package.json` (runtime
> Vue deps) et le vendor aurora a le sien (build tooling vite/vitest).
> Vite résout les deux via Node walk-up.

---

## 2. Configurer l'environnement

```bash
cp .env.local.example .env.local
```

Éditer `.env.local` - variables **obligatoires** :

```dotenv
APP_SECRET=<32-char-random>
DATABASE_URL=postgresql://<user>:<password>@127.0.0.1:5432/<db_name>?serverVersion=16&charset=utf8
AURORA_MOUNT_POINT_KEY=<base64-32-bytes>
AURORA_ENCRYPTION_KEY=<base64-32-bytes>
```

Générer les clés base64 :

```bash
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

> ⚠️ `AURORA_ENCRYPTION_KEY` doit être **stable** dans la durée - si
> tu la changes après avoir saisi des données chiffrées (MountPoints,
> tokens), elles deviennent illisibles. À générer une fois et stocker
> dans un vault d'équipe.

Idem `.env.test.local` (utilisé par `make ft`) :

```bash
cp .env.local .env.test.local
```

Éditer `.env.test.local` pour pointer sur une DB de test distincte (ex:
`<db_name>_test` - note que Doctrine ajoute aussi un suffixe automatique
en mode test).

---

## 3. Setup base de données + lancer le dev

### Option A - PostgreSQL local

Vérifier que ta DB ciblée par `DATABASE_URL` existe (ou la créer) :

```bash
psql -h 127.0.0.1 -U <user> -d postgres -c "CREATE DATABASE <db_name>;"
```

### Option B - Docker (si tu n'as pas PG local)

```bash
make docker-up      # docker-compose up postgres
```

### Tout en une commande

```bash
make install-dev
```

Cette cible enchaîne (sur DB *fresh* ou existante - elle drop + recrée
unconditionnellement) :

1. Composer + pnpm install (idempotent)
2. `doctrine:database:drop --force --if-exists` + `database:create`
3. `doctrine:schema:create` depuis les annotations d'entité (vendor + client)
4. `doctrine:migrations:sync-metadata-storage` + `migrations:version --add --all`
   (marque toutes les migrations comme appliquées - workaround multi-namespace)
5. `doctrine:fixtures:load` (charge les users + données dev)
6. `aurora:application-parameter` + `aurora:privileges:sync` + `aurora:install`
7. `make dev` → lance Vite

> ⚠️ `make install-dev` est explicitement **"from scratch"** - il
> WIPE ta DB. Si tu veux juste sync du code d'un collègue **sans
> perdre tes données locales**, utiliser `make pull-update` à la place.

> Détails techniques sur le workaround multi-namespace :
> [`../dev/database.md`](../dev/database.md) section
> "DB fresh : `make migrate` ne marche pas".

---

## 4. Accéder à l'app

`make install-dev` se termine par `make dev` qui lance Vite en
foreground. Dans un autre terminal :

```bash
make start-d            # serveur Symfony en background
# ouvrir https://localhost:8000 (ou le port affiché)
```

Ou si tu préfères tout dans une seule fenêtre :
- Ctrl+C pour stopper Vite après install-dev
- `make start` → relance Symfony + Vite ensemble

> ⚠️ Si la page se charge sans CSS/JS, vérifier le symlink
> `public/build` :
> ```bash
> ls -la public/build
> # doit pointer vers: public/build -> ../vendor/axelraboit/aurora/public/build
> ```
> Si manquant, le restaurer : `ln -s ../vendor/axelraboit/aurora/public/build public/build`.
> Cf. [`../dev/assets_vue.md`](../dev/assets_vue.md) §Symlink pour le contexte.

Login admin via les fixtures :

| Champ | Valeur |
|---|---|
| Email | `marie.dupont@aurora.app` (ou autre admin selon les fixtures du projet - chercher `ROLE_ADMIN` ou `ROLE_DEV` en DB) |
| Mot de passe | `password` (convention fixtures aurora) |

```bash
# Si tu doutes du compte admin :
psql -d <db_name> -c "SELECT email, roles FROM core_users WHERE roles::text LIKE '%ADMIN%' OR roles::text LIKE '%DEV%';"
```

---

## 6. Vérifier que tout marche

```bash
make ft            # fix (linters) + tests PHP + JS
```

Tous les tests doivent passer. Si plantage côté DB test → vérifier
`.env.test.local` (DATABASE_URL pointe sur une DB de test accessible).

Si la DB test n'existe pas encore, refaire la séquence du §3 sur la DB
test (en lançant les commandes avec `--env=test`).

---

## Troubleshooting

### "Asset not found" ou page sans CSS/JS

Cf. §4 - symlink `public/build` manquant. Restaurer + `make build`.

### `AURORA_ENCRYPTION_KEY must be a base64-encoded 32-byte key`

`.env.local` (ou `.env.test.local`) n'a pas cette variable. Cf. §2.

### `FATAL: password authentication failed for user "app"`

`.env.test.local` utilise les creds du template (`app` / `!ChangeMe!`)
qui ne matchent pas ta PG locale. Mettre tes vrais creds.

### `relation "core_<table>" does not exist` pendant les migrations

Tu as lancé `make migrate` (incremental) sur une DB fresh - il plante
à cause du quirk multi-namespace. Pour repartir de zéro, utilise
`make install-dev` à la place (drop + recrée + schema:create + fixtures).

`make migrate` est uniquement pour l'incrémental - quand tu sync le
boulot d'un collègue qui a ajouté une migration et que ta DB est déjà
à jour sur tout le reste. `make pull-update` l'inclut.

### `make ft` plante sur `make db-test`

`.env.test.local` mal configuré (creds ou DB qui n'existe pas). Cf. §2.

---

## Workflow quotidien après setup

| Commande | Effet |
|---|---|
| `make start` | Re-démarre Symfony + Vite |
| `make ft` | Fix linters + tests |
| `make fixtures` | Reset DB + reload fixtures |
| `make pull-update` | Récup les commits du collègue + sync deps + migrations |
| `make aurora-update` | Bump aurora-core à sa dernière version |

Doc détaillée : [`../dev/dev_workflow.md`](../dev/dev_workflow.md).
