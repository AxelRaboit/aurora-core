# Déployer un projet aurora-client en production

Ce document décrit ce qu'aurora-core **exige** pour tourner en production et
la séquence de déploiement fournie par le template. Le template est volontairement
**infra-agnostique** : il ne fournit pas de Dockerfile, pas de script
systemd, pas de pipeline CI. À vous d'adapter à votre cible (serveur dédié,
PaaS, Kubernetes, etc.). La cible Makefile `deploy-prod` est un exemple
minimaliste de séquence locale-vers-prod, à reproduire dans votre infra.

> 📋 **Avant de commencer** - checklist exhaustive des prérequis (PHP, Node, PostgreSQL, binaires CLI, vars d'env) : [`../../aurora-core/ops/prerequisites.md`](../../aurora-core/ops/prerequisites.md).
>
> **Docs sœurs dans ce dossier** :
> - [`server_provisioning.md`](server_provisioning.md) - d'une machine nue à l'application servie en HTTPS (PostgreSQL, permissions, vhost, certbot)
> - [`worker_systemd.md`](worker_systemd.md) - service systemd pour le worker Messenger
> - [`apache_xsendfile.md`](apache_xsendfile.md) - `mod_xsendfile` pour servir `var/uploads/`
> - [`github_actions_ci.md`](github_actions_ci.md) - setup du workflow CI GitHub Actions (PAT pour le vendor privé, init DB de test)

---

## 1. Séquence de déploiement standard

Le Makefile expose deux targets :

```bash
make install-prod       # première installation prod sur un serveur fraîchement provisionné
make deploy-prod        # déploiement d'une version taguée (HEAD doit être taggé)
make deploy-check       # état du serveur : boot, migrations, worker, file, HTTP
```

`deploy-prod` arrête le worker Messenger avant de toucher au code et le relance
après, y compris si le déploiement échoue en route. Les deux cibles se terminent
par `deploy-check`. Le nom de l'unité systemd vient de `WORKER_SERVICE`
(défaut `aurora-worker`), à surcharger dans `Makefile.local` ; la vider
désactive proprement la gestion du worker.

### `make install-prod`

```
composer install --no-dev --optimize-autoloader
composer install --working-dir=vendor/axelraboit/aurora --no-dev --no-scripts
pnpm --dir=vendor/axelraboit/aurora install --frozen-lockfile
make setup-dirs                                    # var/cache, var/log
make db-install-prod                               # schema:create + marquage des migrations
aurora:install                                     # données de socle (locales, thème, types, menus)
aurora:application-parameter                       # synchronise ApplicationParameters
make build-prod                                    # build prod des assets Vite
make cc-prod                                       # cache:clear --env=prod + verification du boot
```

Deux étapes méritent une explication.

Le **second `composer install`** restaure le `vendor/` imbriqué d'aurora-core.
Son `package.json` déclare `"@symfony/ux-vue": "file:vendor/symfony/ux-vue/assets"`,
un chemin que le premier `composer install` vient d'effacer en ré-extrayant le
paquet. Sans cette ligne, le `pnpm install` suivant meurt sur un `ENOENT` qui ne
désigne rien de compréhensible.

`build-prod` remplace `build` parce que `build` dépend d'`aurora-vendor-guard`,
qui réinstalle les linters d'aurora-core (php-cs-fixer, phpstan, rector,
twig-cs-fixer) quand il ne les trouve pas. Sur un serveur de prod, ça revient à
installer de l'outillage de dev que personne n'a demandé. `build-prod` fait le
même build sans le guard : le vendor imbriqué dont la build a réellement besoin
est restauré par l'étape dédiée plus haut.

**`db-install-prod` remplace `migrate-f`** parce que sur une base vierge la
chaîne de migrations plante : Doctrine Migrations 3.x traite les namespaces dans
leur ordre de déclaration et non strictement par version, donc une
`ClientMigrations` qui étend une table core passe avant l'`AuroraMigrations` qui
la crée. La cible fait `schema:create`, marque toutes les migrations comme
appliquées, puis `messenger:setup-transports` (la table `messenger_messages`
vient d'une migration qu'on vient justement de marquer sans la jouer, et le DSN
porte `auto_setup=0`). Détail dans [`../dev/database.md`](../dev/database.md).

### `make deploy-prod`

Identique à `install-prod`, **plus** :
- Exige un tag git **exact** sur HEAD (`git describe --exact-match --tags HEAD`).
- Écrit le numéro de version dans le fichier `VERSION` à la racine.
- Ne touche pas à `setup-dirs` (sert au premier provisionnement uniquement).

Pour créer une version :

```bash
# 1. Publier : merger develop sur master via une pull request.
#    Le workflow Release calcule le numéro depuis les commits conventionnels
#    (feat -> mineure, rupture -> majeure, sinon patch), crée le tag vX.Y.Z
#    et publie la release.
gh pr create --base master --head develop --title "release"

# 2. Sur le serveur, après avoir tiré le tag :
make deploy-prod
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
| `AURORA_MOUNT_POINT_KEY` | Clé base64 32 bytes - **doit être stable** entre déploiements (sinon MountPoints chiffrés illisibles) |
| `DEFAULT_URI` | URI publique du site (génération des liens absolus en CLI/scheduler) |
| `MESSENGER_TRANSPORT_DSN` | Par défaut `doctrine://default?auto_setup=0` - peut être pointé sur Redis/RabbitMQ en prod |

> ⚠️ `AURORA_MOUNT_POINT_KEY` change → les MountPoints existants sont
> **illisibles**. À générer une seule fois, à stocker dans le vault.

---

## 3. Assets

`make build` lance `pnpm --dir=vendor/axelraboit/aurora run build` (cf.
`Makefile`). La build sort dans `public/build/` avec versioning par hash
(géré par Vite + Pentatrion ViteBundle).

À déployer :
- **`public/build/`** - assets compilés (versionné via hash, pas besoin de
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
> court - 1h max - pour libérer la mémoire). En dev, `make start-dev-worker`
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
| `var/uploads/` | Médias uploadés (Media, Photo, GED, OCR, notes markdown…) - hors document root, servis via le catch-all `/uploads/{path}` (`UploadsServeController`) |

**Deux comptes écrivent dans `var/`**, et c'est ce qui rend le réglage
contre-intuitif :

- `www-data`, l'utilisateur de PHP-FPM, au runtime ;
- le compte humain qui lance `make deploy-prod`, donc `cache:clear`, les
  migrations et le build.

Un `chown -R www-data:www-data var/` suivi d'un simple `g+rX` prive le second du
droit d'écriture : le déploiement échoue dès `make cc-prod`. Il ne convient que
si le déploiement s'exécute **en tant que** `www-data`, ce que le Makefile ne
fait pas.

Le montage qui tient, propriétaire au déployeur et groupe à `www-data` :

```bash
sudo chown -R <deployer>:www-data .
sudo find var -type d -exec chmod 2775 {} +   # 2 = setgid
sudo find var -type f -exec chmod 664  {} +
sudo usermod -aG www-data <deployer>
```

Le bit setgid est ce qui fait durer le montage : les fichiers créés ensuite par
l'un ou l'autre héritent du groupe. Complétez avec `umask 0002` côté déployeur.

`.env.local` doit rester **lisible par `www-data`** (`640`, groupe `www-data`).
En `600`, PHP-FPM ne le lit plus et Symfony retombe silencieusement sur les
valeurs de `.env`.

Détail complet dans [`server_provisioning.md`](server_provisioning.md) §3.

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

OPcache doit être **activé** en prod (`opcache.enable=1`). Le besoin de le
**reset** après un déploiement, lui, dépend d'un seul réglage :

```bash
php -i | grep opcache.validate_timestamps
```

- `validate_timestamps=On` (le défaut des paquets Debian et Ubuntu, avec
  `revalidate_freq=2`) : PHP relit les fichiers modifiés tout seul en quelques
  secondes. **Aucun reset n'est nécessaire**, et en ajouter un au déploiement
  impose une exigence de root pour rien.
- `validate_timestamps=0` (durcissement fréquent en prod, plus rapide) : PHP ne
  regarde plus jamais le disque. Le reset devient **obligatoire**, sinon le code
  reste en mémoire après le déploiement et produit des bugs sans rapport
  apparent avec la mise en ligne. Passez alors par `systemctl reload php8.4-fpm`
  ou un endpoint d'admin appelant `opcache_reset()`.

Vérifiez le réglage de votre serveur avant de trancher : c'est l'un ou l'autre,
pas une bonne pratique universelle.

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
git fetch --tags
git checkout <new-tag>
make deploy-prod
```

`deploy-prod` enchaîne lui-même l'installation des dépendances, les migrations,
les syncs, le build, le cache prod, l'arrêt et le redémarrage du worker, puis
`deploy-check`. La séquence s'arrête à la première erreur au lieu de continuer,
et le worker est relancé même si elle échoue en route.

Un `systemctl reload php8.4-fpm` n'est à ajouter que si votre OPcache tourne
avec `validate_timestamps=0` (cf. §7).

**Toujours** lire le `CHANGELOG.md` d'aurora-core avant un déploiement
majeur (les breaking changes sont préfixés `BREAKING:`).

---

## 11. Sauvegardes

Un serveur Aurora porte trois choses qui ne se reconstruisent pas depuis git.
Tant qu'elles ne sont pas sauvegardées ailleurs, l'installation n'est pas
terminée.

| Quoi | Pourquoi c'est irremplaçable |
|---|---|
| `.env.local` | `AURORA_MOUNT_POINT_KEY` et `AURORA_ENCRYPTION_KEY` (cf. §2). Les perdre rend illisibles les MountPoints et les champs chiffrés **déjà en base**. Aucune restauration de base ne les récupère. |
| La base | Contenu, utilisateurs, paramètres applicatifs |
| `var/uploads/` | Tous les fichiers déposés par les utilisateurs. Le code ne les régénère pas. |

Le reste (code, schéma, assets) se reconstruit depuis un tag et
`make install-prod`.

Une sauvegarde minimale, quotidienne :

```bash
pg_dump -Fc <db_name> > db-$(date +%F).dump
tar czf config-$(date +%F).tar.gz .env.local /etc/apache2/sites-available/<projet>*.conf \
    /etc/systemd/system/<projet>-worker.service /etc/letsencrypt
tar czf uploads-$(date +%F).tar.gz -C var uploads
```

Deux points qui distinguent une sauvegarde d'un fichier qui grossit :

- **Vérifiez le dump à la production**, pas au moment de la panne :
  `pg_restore --list db-*.dump` doit s'exécuter sans erreur. Un dump illisible
  s'accumule des mois sans que rien ne le signale.
- **Testez une restauration réelle** au moins une fois, dans une base jetable.
  Une sauvegarde jamais restaurée est une hypothèse, pas une garantie.

Une copie sur la machine elle-même couvre la suppression accidentelle, la
migration ratée et la corruption applicative, qui sont les incidents les plus
fréquents. Elle ne couvre ni la panne disque ni la perte du serveur : pour
ceux-là il faut une copie hors-machine.
