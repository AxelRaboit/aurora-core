# Setup — Installation locale

> 🚀 **Tu rejoins un projet existant ?** Voir le quickstart 10 min :
> [`joining_a_project.md`](joining_a_project.md). Procédure
> copier-coller qui marche du premier coup (contourne le piège
> multi-namespace de Doctrine Migrations).
>
> **Tu démarres un nouveau projet ?** Aurora-client est le template à
> dupliquer. Voir la section
> [Démarrer un nouveau projet](#démarrer-un-nouveau-projet) plus bas.
>
> Ce doc-ci = **référence longue** (env vars exhaustives, options
> Docker, troubleshooting détaillé). Pour le démarrage rapide, voir
> les liens ci-dessus.

## Prérequis

| Outil | Version minimale | Notes |
|---|---|---|
| PHP | 8.4 | |
| Composer | 2.x | |
| PostgreSQL | 18+ | en prod et dans le template `.env.local.example` |
| Node.js | 20+ | |
| pnpm | 10+ | |
| php8.4-pcov | — | driver de coverage PHPUnit (optionnel, pour `--coverage`) |
| Docker (optionnel) | — | pour la base de données en local |

Installer PCOV :

```bash
sudo apt install php8.4-pcov
```

---

## 1. Cloner le dépôt

```bash
git clone git@github.com:<org>/aurora-client.git
cd aurora-client
```

---

## 2. Installer les dépendances

```bash
composer install                                # vendor PHP
pnpm install                                    # deps JS client (Vue, axios, …)
(cd vendor/axelraboit/aurora && pnpm install)   # tooling Vite/Vitest (vendor)
```

> ✅ `make install-dev` fait tout ça en un raccourci — composer +
> pnpm install + drop/recrée DB + schema:create + fixtures + Vite.
> C'est le chemin recommandé en pratique. Les étapes manuelles
> ci-dessous (§3 + §4) sont la version détaillée si tu veux
> comprendre ou si tu fais juste un re-setup partiel.

---

## 3. Configurer l'environnement

Copie le fichier d'exemple et renseigne tes valeurs locales :

```bash
make setup-env
# édite ensuite .env.local
```

Variables obligatoires dans `.env.local` :

```dotenv
APP_SECRET=<chaine-aléatoire-32-chars>
DATABASE_URL=postgresql://app:password@127.0.0.1:5432/aurora_client_dev?serverVersion=18&charset=utf8
```

Variables optionnelles (déjà définies dans `.env`, à surcharger si besoin) :

```dotenv
MAILER_DSN=smtp://localhost:1025          # Mailpit en local
MAILER_FROM=noreply@aurora-client.local
ADMIN_EMAIL=admin@aurora-client.local
APP_NAME=aurora-client
```

---

## 4. Base de données

### Option A — Docker (recommandé)

```bash
make docker-up     # démarre PostgreSQL en conteneur
```

### Option B — PostgreSQL local

Crée la base manuellement :

```bash
psql -h 127.0.0.1 -U <user> -d postgres -c "CREATE DATABASE <db_name>;"
```

### Init schéma + state migrations (les deux options)

Le plus simple : `make install-dev` (déjà mentionné §2) le fait pour
toi. Si tu veux le faire à la main, ou si tu setup juste la DB sans
l'install complet :

```bash
php bin/console doctrine:schema:create                       # schéma depuis entités
php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
php bin/console doctrine:migrations:version --add --all --no-interaction
php bin/console doctrine:schema:validate                     # sanity check
php bin/console aurora:application-parameter
php bin/console aurora:privileges:sync
php bin/console aurora:menus:sync
php bin/console doctrine:fixtures:load --no-interaction      # données dev
```

> ⚠️ Ne PAS faire `make migrate` directement sur une DB fresh — il
> plante à cause du quirk multi-namespace. Utiliser `make install-dev`
> (qui contient le workaround) ou la séquence manuelle ci-dessus.
> `make migrate` reste correct pour l'incrémental (pull d'un collègue).
> Détails dans [`../dev/database.md`](../dev/database.md) section
> "DB fresh : `make migrate` ne marche pas".

---

## 5. Démarrer le serveur de développement

```bash
make start
```

Démarre en parallèle :
- Le serveur Symfony (`symfony server:start`)
- Vite (`pnpm --dir=vendor/axelraboit/aurora dev`)

L'application est accessible sur `https://localhost:8000` (ou le port affiché).

Pour démarrer sans TLS (HTTPS) :

```bash
make start-no-tls
```

---

## 6. Compte administrateur par défaut

Les fixtures créent un compte dev :

| Champ | Valeur |
|---|---|
| Email | `admin@aurora-client.local` (ou valeur de `ADMIN_EMAIL`) |
| Mot de passe | défini dans `DataFixtures/` |
| Rôle | `ROLE_DEV` — accès complet, bypass de tous les privilege checks |

---

## 7. Vérifier que tout fonctionne

```bash
make ft   # fix (linters) + tests (PHP + JS)
```

Les tests tournent contre la base de test (`aurora_client_test`) créée
automatiquement par `make db-test`. Tous les tests doivent passer en vert.

---

## 8. Variables d'environnement complètes

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_ENV` | `dev` | Environnement Symfony |
| `APP_SECRET` | *(à définir)* | Clé de chiffrement sessions/CSRF |
| `DATABASE_URL` | `postgresql://…/aurora-client` | Connexion PostgreSQL |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Transport async |
| `MAILER_DSN` | `smtp://localhost:1025` | Envoi d'emails |
| `MAILER_FROM` | `noreply@aurora-client.local` | Expéditeur des emails |
| `ADMIN_EMAIL` | `admin@aurora-client.local` | Email du compte admin par défaut |
| `APP_NAME` | `aurora-client` | Nom de l'application (affiché dans l'UI) |
| `APP_SHARE_DIR` | `var/share` | Dossier partagé entre workers |
| `DEFAULT_URI` | `http://localhost` | URI de base pour les emails |
| `OCR_DOCTR_URL` | *(optionnel)* | URL du microservice docTR (module Billing/OCR) |
| `OLLAMA_URL` | *(optionnel)* | URL Ollama pour l'extraction OCR |
| `OLLAMA_VISION_MODEL` | `qwen2.5vl:3b` | Modèle vision Ollama |

---

## Démarrer un nouveau projet

Aurora-client est le **template à dupliquer** pour tout nouveau projet :

```bash
git clone git@github.com:<org>/aurora-client.git mon-projet
cd mon-projet
rm -rf .git && git init
```

Ensuite :

1. Mettre à jour `composer.json` (name, description).
2. Mettre à jour `.env` (`APP_NAME`, `DATABASE_URL`).
3. Si ton point de départ contient des modules dont tu n'as pas besoin
   (exemples scaffoldés, reliquat d'un ancien template), les retirer
   (cf. checklist ci-dessous).
4. Setup DB fresh — **ne pas faire `make install-dev` directement** sur
   un fresh clone (cf. note ci-dessous).
5. `git commit -m "chore: init project from aurora-client template"`.

> ⚠️ **Conserver `public/build`** durant le cleanup. C'est un symlink
> versionné (`public/build → ../vendor/axelraboit/aurora/public/build`)
> indispensable au chargement des assets. Si tu fais un `rm -rf
> public/*` en cleanup, recrée-le après. Détails dans
> [`../dev/assets_vue.md`](../dev/assets_vue.md) §Symlink.

### Checklist — retirer un module client

> Le template démarre **propre** (aucun module métier livré). Cette checklist
> sert quand tu retires un module que tu as scaffolté, un exemple que tu as
> reconstruit en suivant la doc (`Tracking`, extension `Agency`…), ou un
> reliquat hérité d'un ancien template.

Pour chaque module à retirer :

```bash
# 1. Code source du module
rm -rf src/Module/<X>/

# 2. Tests s'il y en a
rm -rf tests/Unit/Module/<X>/

# 3. Migrations qui touchent UNIQUEMENT ce module
#    (vérifier ce qu'elles font avant de supprimer)
rm migrations/Version<YYYYMMDD>_<X>_*.php

# 4. Configs qui référencent le module
#    Retirer manuellement la ligne pour <X> dans :
#    - config/packages/doctrine.yaml      (resolve_target_entities)
#    - config/packages/twig.yaml          (namespace @X)
#    - config/packages/framework.yaml     (translator.paths)
#    - config/services.yaml               (DumpJsTranslationsCommand $extraSourceDirs)
#    - jsconfig.json                      (@x alias)
#    - src/locales/{en,fr}.js             (labels du module)

# 5. Si la DB existe déjà : nettoyer les tables / sequences orphelines
psql -d <db_name> -c "DROP TABLE IF EXISTS <table> CASCADE;"
psql -d <db_name> -c "DROP SEQUENCE IF EXISTS seq_<x>_id CASCADE;"
psql -d <db_name> -c "DELETE FROM doctrine_migration_versions WHERE version LIKE 'ClientMigrations\Version<YYYY...>';"

# 6. Cache + verify
php bin/console cache:clear --env=dev
php bin/console doctrine:schema:validate
```

### Setup DB fresh (au lieu de `make install-dev`)

Sur une DB *vierge*, `make migrate` plante à cause de l'interleaving
multi-namespace de Doctrine Migrations. Procédure recommandée :

```bash
php bin/console doctrine:database:create
php bin/console doctrine:schema:create                       # schéma depuis entités
php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
php bin/console doctrine:migrations:version --add --all --no-interaction
php bin/console doctrine:schema:validate                     # sanity check
php bin/console aurora:application-parameter
php bin/console aurora:privileges:sync
php bin/console aurora:menus:sync
php bin/console doctrine:fixtures:load --no-interaction      # optionnel — dev data
```

Détails techniques dans [`../dev/database.md`](../dev/database.md)
section "DB fresh : `make migrate` ne marche pas".
