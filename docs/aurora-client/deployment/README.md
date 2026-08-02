# Déployer un projet aurora-client en production

Ce document décrit ce qu'aurora-core **exige** pour tourner en production et
la séquence de déploiement fournie par le template. Le template est volontairement
**infra-agnostique** : il ne fournit pas de Dockerfile, pas de script
systemd, pas de pipeline CI. À vous d'adapter à votre cible (serveur dédié,
PaaS, Kubernetes, etc.). La cible Makefile `deploy-prod` est un exemple
minimaliste de séquence locale-vers-prod, à reproduire dans votre infra.

> 📋 **Avant de commencer** — checklist exhaustive des prérequis (PHP, Node, PostgreSQL, binaires CLI, vars d'env) : [`../../aurora-core/ops/prerequisites.md`](../../aurora-core/ops/prerequisites.md).
>
> **Docs sœurs dans ce dossier** :
> - [`worker_systemd.md`](worker_systemd.md) — service systemd pour le worker Messenger
> - [`apache_xsendfile.md`](apache_xsendfile.md) — `mod_xsendfile` pour servir `var/uploads/`
> - [`github_actions_ci.md`](github_actions_ci.md) — setup du workflow CI GitHub Actions (PAT pour le vendor privé, init DB de test)

---

## 1. Séquence de déploiement standard

Le Makefile expose deux targets :

```bash
make install-prod       # première installation prod sur un serveur fraîchement provisionné
make deploy-prod        # déploiement d'une version taguée (HEAD doit être taggé)
```

### `make install-prod`

```
composer install --no-dev --optimize-autoloader
pnpm --dir=vendor/axelraboit/aurora install --frozen-lockfile
make setup-dirs                                    # var/cache, var/log
make migrate-f                                     # doctrine:migrations:migrate --no-interaction
aurora:application-parameter                       # synchronise ApplicationParameters
aurora:install                                     # données de socle (locales, thème, types, menus)
make build                                         # build prod des assets Vite
make cc-prod                                       # cache:clear --env=prod + verification du boot
```

### `make deploy-prod`

Identique à `install-prod`, **plus** :
- Exige un tag git **exact** sur HEAD (`git describe --exact-match --tags HEAD`).
- Écrit le numéro de version dans le fichier `VERSION` à la racine.
- Ne touche pas à `setup-dirs` (sert au premier provisionnement uniquement).

Pour créer une version :

```bash
make tag VERSION=1.2.3      # git tag + git push origin 1.2.3
make deploy-prod            # sur le serveur, après pull du tag
```

---

## 2. Variables d'environnement à fournir en prod

Définir dans `.env.local` (ou variables d'environnement du PaaS) :

| Variable | Description |
|---|---|
| `APP_ENV=prod` | Toujours `prod` |
| `APP_DEBUG=0` | **Obligatoire** |
| `APP_SECRET` | Générer un secret cryptographiquement sûr |
| `DATABASE_URL` | DSN PostgreSQL prod (`?serverVersion=18`) |
| `MAILER_DSN` | SMTP prod (jamais `smtp://localhost:1025`) |
| `MAILER_FROM` | Adresse expéditeur |
| `ADMIN_EMAIL` | Adresse admin recevant les notifications système |
| `AURORA_MOUNT_POINT_KEY` | Clé base64 32 bytes — **doit être stable** entre déploiements (sinon MountPoints chiffrés illisibles) |
| `DEFAULT_URI` | URI publique du site (génération des liens absolus en CLI/scheduler) |
| `MESSENGER_TRANSPORT_DSN` | Par défaut `doctrine://default?auto_setup=0` — peut être pointé sur Redis/RabbitMQ en prod |

> ⚠️ `AURORA_MOUNT_POINT_KEY` change → les MountPoints existants sont
> **illisibles**. À générer une seule fois, à stocker dans le vault.

---

## 3. Assets

`make build` lance `pnpm --dir=vendor/axelraboit/aurora run build` (cf.
`Makefile`). La build sort dans `public/build/` avec versioning par hash
(géré par Vite + Pentatrion ViteBundle).

À déployer :
- **`public/build/`** — assets compilés (versionné via hash, pas besoin de
  cache-bust manuel).
- **Pas** `node_modules/`, **pas** `assets/` source.

Côté serveur HTTP : servir `public/` en docroot, `public/build/` doit être
public, le reste de l'arborescence non.

---

## 4. Post-déploiement

À chaque déploiement (`deploy-prod` s'en charge déjà) :

```bash
php bin/console aurora:application-parameter       # nouveaux paramètres applicatifs
php bin/console aurora:install                     # données de socle des modules (idempotent)
php bin/console aurora:privileges:sync             # privilèges des modules (après ajout de NavPermission)
```

À lancer **manuellement** si des séquences ont été touchées par un import :

```bash
make sync-sequences                                 # aurora:sequences:resync
```

Voir [`database.md`](database.md) pour le détail des syncs.

---

## 5. Scheduler / worker async

Aurora utilise **Symfony Scheduler** pour ses tâches récurrentes (publication
de posts programmés, purge de corbeille, OCR billing…). Détails côté core :
[`../../aurora-shared/scheduler.md`](../../aurora-shared/scheduler.md).

**Pas de cron système requis.** Un worker Messenger consomme les deux
transports `async` + `scheduler_main`. À lancer en service système (systemd,
supervisor, conteneur dédié) :

```bash
php bin/console messenger:consume async scheduler_main --time-limit=3600 --memory-limit=512M -vv
```

> Le worker **doit** être supervisé (auto-restart sur exit, time-limit
> court — 1h max — pour libérer la mémoire). En dev, `make start-dev-worker`
> tourne dans une boucle while + sleep, à ne pas utiliser en prod.

Sans worker tournant :
- Les emails partent en synchrone (ralentit les requêtes HTTP).
- Les posts programmés ne sont jamais publiés.
- L'OCR Billing ne tourne pas.
- Les notifications async sont buffer dans la table `messenger_messages` et
  jamais consommées.

---

## 6. Permissions filesystem

Aurora écrit dans :

| Chemin | Pourquoi |
|---|---|
| `var/cache/` | Cache Symfony (généré au runtime) |
| `var/log/` | Logs |
| `var/share/` (cf. `APP_SHARE_DIR`) | Fichiers partagés temporaires |
| `var/uploads/` | Médias uploadés (Media, Photo, GED, OCR, notes markdown…) — hors document root, servis via le catch-all `/uploads/{path}` (`UploadsServeController`) |

Le user PHP-FPM / CLI doit avoir le droit **rwx** sur `var/` (qui couvre les
4 sous-dossiers ci-dessus). Sur serveur Apache + PHP-FPM standard :

```bash
chown -R www-data:www-data var/
chmod -R u+rwX,g+rX,o+rX var/
```

> Convention storage : tous les fichiers utilisateur vivent sous `var/uploads/`,
> hors document root, servis par PHP avec auth granulaire. Voir la mémoire
> [`convention_storage_var_uploads.md`](../../../.claude/memory/aurora-shared/convention_storage_var_uploads.md)
> et `apache_xsendfile.md` pour le offload prod.

---

## 7. Cache et OPcache

`make cc-prod` :

```
APP_ENV=prod APP_DEBUG=0 bin/console cache:clear --env=prod
APP_ENV=prod APP_DEBUG=0 bin/console about --env=prod   # vérification du boot
```

OPcache doit être :
- **activé** en prod (`opcache.enable=1`)
- **reset** après chaque déploiement (`opcache_reset()` via un endpoint
  d'admin, ou redémarrer PHP-FPM : `systemctl reload php8.4-fpm`)

Sinon : code stale en mémoire après deploy → bugs silencieux.

---

## 8. Healthcheck

Aurora n'expose pas d'endpoint `/_health` dédié. Patterns acceptables :

- Hit `GET /` qui doit retourner 200 + HTML (route Frontend par défaut).
- Hit `GET /backend` qui doit retourner 302 (redirect login) sans 500.
- En cas de scheduler / worker : monitorer la latence des messages dans
  `messenger_messages` (rows non-consommées depuis > N minutes = alerte).

---

## 9. Pièges connus

- **Migrations Aurora oubliées** : `make migrate-f` joue les migrations
  `DoctrineMigrations` (client) **et** `AuroraMigrations` (vendor) dans
  l'ordre. Ne **jamais** lancer une migration manuelle qui sélectionne un
  seul namespace en prod, sinon désordre.
- **`security.yaml` non synchronisé** : la cible `make sync-security`
  écrase `config/packages/security.yaml` depuis le vendor à chaque
  `make aurora-update`. Si vous customisez la sécurité, le faire dans un
  autre fichier (`config/packages/security_custom.yaml` qui surcharge,
  ou via un `EventSubscriber`).
- **`.env.local` absent sur le serveur** : Symfony tombe sur les valeurs
  par défaut de `.env` (DSN `localhost`) → boot prod cassé silencieusement.
- **OPcache non reset** : nouveaux fichiers PHP non vus par PHP-FPM →
  500 sur des routes qui marchaient avant deploy.
- **Worker absent** : voir §5.

---

## 10. Mise à jour d'aurora-core en prod

Le flux est documenté dans [`update_aurora.md`](update_aurora.md). En prod,
la séquence à automatiser :

```bash
git pull --tags
git checkout <new-tag>
composer install --no-dev --optimize-autoloader
pnpm --dir=vendor/axelraboit/aurora install --frozen-lockfile
make migrate-f
make sf CMD="aurora:application-parameter"
make sf CMD="aurora:privileges:sync"
make build
make cc-prod
systemctl reload php8.4-fpm
systemctl restart aurora-worker          # votre service supervisor du messenger:consume
```

**Toujours** lire le `CHANGELOG.md` d'aurora-core avant un déploiement
majeur (les breaking changes sont préfixés `BREAKING:`).
