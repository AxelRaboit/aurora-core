# Setup : nouveau projet et variables d'environnement

> 🚀 **Tu rejoins un projet existant ?** Tout est dans
> [`joining_a_project.md`](joining_a_project.md) : prérequis,
> installation, quotidien, fixtures. C'est la procédure de référence.
>
> Ce doc-ci couvre deux choses : **démarrer un nouveau projet** à partir
> d'aurora-client, et la **référence des variables d'environnement**.

Les versions des outils (PHP, Node, pnpm, PostgreSQL...) ne sont tenues
qu'à un endroit : [`../../aurora-core/ops/prerequisites.md`](../../aurora-core/ops/prerequisites.md).

---

## Démarrer un nouveau projet

Aurora-client est le **projet modèle à dupliquer** pour tout nouveau
projet :

```bash
git clone git@github.com:<org>/aurora-client.git mon-projet
cd mon-projet
rm -rf .git && git init
```

Ensuite :

1. Mettre à jour `composer.json` (`name`, `description`).
2. Mettre à jour `.env` (`APP_NAME`, et le nom de base dans
   `DATABASE_URL`).
3. Renommer le titre et l'intro du `README.md`, au-dessus du marqueur
   `aurora-canonical:start` (cf.
   [Ce que les synchronisations n'écrasent jamais](joining_a_project.md#ce-que-les-synchronisations-nécrasent-jamais)).
4. Si ton point de départ contient des modules dont tu n'as pas besoin
   (exemples scaffoldés, reliquat d'un ancien modèle), les retirer
   (cf. checklist ci-dessous).
5. Installer en local en suivant [`joining_a_project.md`](joining_a_project.md) :
   `make setup-env`, `.env.test.local`, puis `make install-dev`. Sur ce
   clone neuf, `make install-dev` se lance **une fois** ; ce qui est
   interdit, c'est de le relancer ensuite (il supprime la base).
6. `git commit -m "chore: init project from aurora-client template"`.

> ⚠️ **Conserver `public/build`** durant le nettoyage. C'est un lien
> symbolique versionné (`public/build → ../vendor/axelraboit/aurora/public/build`)
> indispensable au chargement des assets. Si tu fais un `rm -rf
> public/*`, recrée-le après. Détails dans
> [`../dev/assets_vue.md`](../dev/assets_vue.md) §Symlink.

### Checklist : retirer un module client

> Le modèle démarre **propre** (aucun module métier livré). Cette
> checklist sert quand tu retires un module que tu as scaffoldé, un
> exemple que tu as reconstruit en suivant la doc (`Tracking`, extension
> `DocumentCategory`...), ou un reliquat hérité d'un ancien modèle.

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

---

## Initialiser une base à la main

`make install-dev` (premier clone) et `make fixtures` (reset) le font
pour toi. La séquence, si tu veux la comprendre ou la rejouer sur une
base vide sans réinstaller les dépendances, dans l'ordre du Makefile :

```bash
php bin/console doctrine:database:create
php bin/console doctrine:schema:create                       # schéma depuis les entités
php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
php bin/console doctrine:migrations:version --add --all --no-interaction
php bin/console messenger:setup-transports                   # table messenger_messages
php bin/console aurora:install                               # données de socle, AVANT les fixtures
php bin/console doctrine:fixtures:load --no-interaction --append   # optionnel : données de dev
php bin/console aurora:application-parameter
php bin/console aurora:privileges:sync
```

`aurora:install` passe avant les fixtures, qui construisent leur contenu
par-dessus ; `--append` empêche les fixtures de purger ce socle.

> ⚠️ Ne lance pas `make migrate` sur une base vide : les migrations du
> client et celles d'aurora-core ne s'y entrelacent pas dans le bon
> ordre. `make migrate` reste correct en incrémental (pull d'un
> collègue). Détails dans [`../dev/database.md`](../dev/database.md),
> section "DB fresh : `make migrate` ne marche pas".

---

## Compte de connexion des fixtures

| Champ | Valeur |
|---|---|
| Email | `dev@aurora.app` |
| Mot de passe | `password` |
| Rôle | `ROLE_DEV` : accès complet, contourne tous les contrôles de privilèges |

Ce compte n'existe qu'avec les fixtures de dev ; il n'est jamais créé en
production.

---

## Variables d'environnement

`make setup-env` crée `.env.local` depuis `.env.local.example` et y
génère `APP_SECRET`, `AURORA_MOUNT_POINT_KEY` et `AURORA_ENCRYPTION_KEY`.
Les défauts versionnés vivent dans `.env` ; `make sync-env` y ajoute les
blocs `###> aurora/* ###` manquants sans jamais toucher aux valeurs
existantes.

L'environnement de test ne lit pas `.env.local` : `.env.test.local`
doit porter `DATABASE_URL` et les deux clés Aurora (cf.
[`joining_a_project.md`](joining_a_project.md) §3).

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_ENV` | `dev` | Environnement Symfony |
| `APP_SECRET` | *(généré par `make setup-env`)* | Clé de chiffrement sessions/CSRF |
| `DATABASE_URL` | `postgresql://…/aurora_client` | Connexion PostgreSQL |
| `AURORA_MOUNT_POINT_KEY` | *(généré par `make setup-env`)* | Clé base64 de 32 octets, chiffrement des points de montage |
| `AURORA_ENCRYPTION_KEY` | *(généré par `make setup-env`)* | Clé base64 de 32 octets, chiffrement générique (champs chiffrés en base). À garder stable |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Transport async |
| `MAILER_DSN` | `smtp://localhost:1025` | Envoi d'emails (Mailpit en local) |
| `MAILER_FROM` | `noreply@aurora-client.local` | Expéditeur des emails |
| `ADMIN_EMAIL` | `admin@aurora-client.local` | Destinataire des notifications d'administration (formulaires, commentaires à modérer) quand le réglage n'est pas renseigné dans le back-office |
| `APP_NAME` | `aurora-client` | Nom de l'application (affiché dans l'UI) |
| `APP_SHARE_DIR` | `var/share` | Dossier partagé entre workers |
| `DEFAULT_URI` | `http://localhost` | URI de base pour les emails |

Générer une clé à la main si besoin :

```bash
php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
```

Ne jamais committer `.env.local` ni `.env.test.local` (gitignorés).
