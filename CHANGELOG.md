# Changelog Aurora-core

Format : [SemVer](https://semver.org). Section **"Dans aurora-client"** = ce que les
projets clients doivent répercuter après avoir lancé `make aurora-update`.

---

## [Unreleased]

_Rien pour l'instant. Les entrées s'ajoutent ici au fil des commits ; la
section est close en `## [X.Y.Z] - AAAA-MM-JJ` dans la pull request qui merge
`develop` sur `master`, et c'est cette fermeture qui déclenche la release._

## [0.9.14] - 2026-08-30

### Corrigé

#### Des libellés traduits nommaient le framework
- Une installation cliente est le site de quelqu'un : son nom dans les
  réglages, son domaine, sa marque. Cinq chaînes disaient « Aurora » quand
  même, et deux disaient quelque chose de faux : le badge gris « Fourni par
  Aurora » à côté d'un type de contenu veut dire que le type est natif, pas
  qu'Aurora fournit le contenu.
- Les deux badges `built_in` deviennent « Natif » / « Native » / « Built-in ».
- Le placeholder du titre de bandeau devient « Bienvenue » / « Welcome ».
- Le placeholder du nom du site devient « Mon entreprise » / « My company ».
  Il se trouvait dans le champ même qu'un propriétaire modifie pour nommer son
  site, et proposait le nom du framework comme exemple. Le fichier anglais
  portait en plus la chaîne française telle quelle.
- Un test lit désormais chaque fichier de traduction sous `src/` et échoue sur
  toute valeur traduite qui nomme le framework.

### Dans aurora-client

Rien à faire.

## [0.9.13] - 2026-08-30

### Supprimé

#### Dix-huit modules de `shared/`, résidus du split abandonné
- Tous étaient sans consommateur. Leurs anciens appelants sont partis avec les
  modules extraits du monorepo (Billing, Crm, Tools, Erp, Ecommerce,
  PersonalFinance, Assistant) ou avec des panneaux Editorial retirés au même
  moment. **Les quatorze dépôts `aurora-*` correspondants sont archivés** :
  seuls aurora-core et aurora-client sont vivants, et aucun des deux n'importe
  ces fichiers.
- Composables : `useDetailDelete`, `useFormModal`, `useInlineEdit`,
  `useSlugLock`, `useLoadMore`, `useUrlPagination`, `useInfiniteScroll`,
  `usePasswordGenerator`.
- Utilitaires : `parseJson`, `blocksRenderer`, `mergeBlocks`, `revisionDiff`,
  `currencies`, `formatPrice`, `parseMoney`, `pickTranslation`, `seoCounter`,
  `passwordStrength`.
- Soit 28 fichiers avec leurs tests, environ 1700 lignes. Les entrées
  correspondantes sortent de `composables_catalog.md`, et les deux mémoires qui
  leur étaient dédiées (`composable_url_pagination`, `utility_pick_translation`)
  disparaissent avec leur index.

#### Ce que cette suppression a coûté en allers-retours
- La 0.9.11 avait retiré `useSlugLock` seul, la 0.9.12 l'avait restauré parce
  qu'`aurora-editorial` l'importe. Les deux décisions reposaient sur une
  question jamais posée : ces dépôts sont-ils vivants ? Ils ne le sont pas.
  La mémoire du projet le disait déjà pour Editorial, revenu dans le core en
  août ; l'API GitHub le confirme pour les treize autres.
- **La vérification qui compte n'est pas « qui importe ce fichier », c'est
  « ce consommateur existe-t-il encore ».** Un `grep` dans les dépôts frères
  répond oui à la première et masque la seconde.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Un projet client qui importerait
l'un de ces fichiers depuis `@/shared/` doit en revanche le copier chez lui
avant de mettre à jour ; l'historique git d'aurora-core en conserve la version
exacte.

## [0.9.12] - 2026-08-30

### Corrigé

#### `useSlugLock` est restauré : la 0.9.11 l'a supprimé à tort
- La 0.9.11 affirmait que rien ne l'utilisait. C'est faux, et la vérification
  était incomplète : elle portait sur aurora-core et sur un projet client, pas
  sur les **paquets de modules extraits**. `aurora-editorial` l'importe depuis
  `@/shared/composables/form/useSlugLock.js` et l'utilise avec son cadenas
  (`slugLocked`, `toggleSlugLock`) dans son éditeur de post. La 0.9.11 casse
  donc ce paquet ; **ne restez pas dessus si vous installez `aurora-editorial`**.
- Le composable, son test et son entrée au catalogue sont remis en place à
  l'identique. L'entrée précise désormais qui le consomme, en quoi il diffère
  de `slugifyIfEmpty`, et qu'il ne doit être câblé qu'avec son `toggle` affiché.

#### La leçon, pour les prochains ménages
- `shared/` du core est consommé par les paquets frères, pas seulement par
  aurora-core et les projets clients. Un `grep` dans ce dépôt ne prouve donc
  rien : dix-sept autres modules de `shared/` ressortent « inutilisés » au même
  test, et se révèlent tous être des dépendances vivantes de `aurora-billing`,
  `aurora-crm`, `aurora-tools`, `aurora-erp` et consorts, ou des modules
  Editorial retirés du monorepo au moment du split.
- Aucun d'eux n'est supprimé. Avant de retirer quoi que ce soit de `shared/`,
  la vérification doit couvrir les dépôts `aurora-*` du compte.

### Dans aurora-client

Rien à faire. Un projet client qui serait passé par la 0.9.11 sans installer
`aurora-editorial` n'a rien vu ; la mise à jour rétablit simplement le fichier.

## [0.9.11] - 2026-08-30

### Supprimé

#### `useSlugLock`, jamais utilisé et redondant depuis la 0.9.10
- Le composable n'était appelé nulle part, ni dans aurora-core ni dans un
  projet client, alors qu'il était documenté au catalogue. Une API annoncée
  que personne n'a jamais branchée est une promesse que rien ne vérifie.
- Il est surtout redondant depuis que la 0.9.10 a câblé `slugifyIfEmpty()`
  pour le même besoin. Deux mécanismes pour dériver un slug d'un titre, c'est
  la garantie qu'un jour le mauvais soit choisi : celui-ci est verrouillé par
  défaut, donc le slug y suit le titre en permanence, y compris sur un contenu
  publié dont l'URL est déjà partagée.
- Son entrée est retirée de `composables_catalog.md`. `slugify()` et
  `slugifyIfEmpty()`, qui y figuraient déjà, restent la façon de faire.

> Le motif « cadenas ouvrable » n'est pas mauvais en soi, il demande juste
> l'affordance qui va avec, un bouton pour déverrouiller. Si le besoin revient,
> l'historique git contient la quinzaine de lignes.

### Dans aurora-client

Rien à faire, sauf pour un projet qui importerait `useSlugLock` depuis
`@/shared/composables/form/`. Aucun ne le fait à notre connaissance. Le
remplacement tient en un appel :

```js
translation.slug = slugifyIfEmpty(translation.slug, translation.title);
```

Il ne remplit que si le slug est vide, donc ne réécrit jamais une URL existante.

## [0.9.10] - 2026-08-30

### Modifié

#### L'éditeur de post utilise enfin les composants maison
- Les deux champs de date, « publier le » et « dépublier le », étaient des
  `<AppInput type="datetime-local">`, donc le sélecteur natif du navigateur :
  apparence différente à chaque navigateur, et sans rapport avec le reste du
  backend. Ils passent à `AppDatePicker` avec `enable-time`, qui émet
  exactement le même format `YYYY-MM-DDTHH:MM` : aucune donnée existante n'est
  affectée.
- « Commentaires ouverts » était une case à cocher là où le projet rend
  partout ailleurs ce genre de bascule avec `AppToggle`. C'est désormais un
  interrupteur.

### Ajouté

#### Le slug se remplit depuis le titre
- À la saisie du titre, le slug de la même langue se remplit **s'il est vide**,
  et seulement dans ce cas. Écrire par-dessus un slug existant changerait l'URL
  d'un contenu déjà publié sur une simple correction de titre, et casserait le
  référencement sans rien dire.
- `slugifyIfEmpty()` existait déjà dans `shared/utils/format/slugify.js`, avec
  cette sémantique exacte et ses tests, mais n'était utilisé nulle part. Il est
  simplement câblé.
- Le comportement est par langue : le titre français remplit le slug français.
  Changer d'onglet de langue ne déclenche rien, une traduction qui a déjà un
  titre sans slug n'en reçoit pas un que personne n'a demandé.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`. Aucun format de données ne
change, et le remplissage du slug ne touche jamais une valeur existante.

> À noter pour plus tard : `shared/composables/form/useSlugLock.js` est du code
> mort lui aussi, mais sa sémantique diffère. Il est verrouillé par défaut,
> donc le slug suit le titre en permanence, y compris sur un contenu publié.
> Le câbler tel quel réécrirait des URLs en production.

## [0.9.9] - 2026-08-30

### Corrigé

#### Les notifications à l'administrateur partaient vers un domaine inexistant
- `backend_email` était semé avec `admin@aurora.app`, et `MailService::adminEmail()`
  retournait cette valeur telle quelle. Or `sendToAdmin()` est appelé par
  `FormNotificationService` et `CommentNotificationService` : sur toute
  installation où personne n'a pensé à changer ce réglage, **chaque soumission
  de formulaire et chaque commentaire en attente partait vers un domaine qui
  n'existe pas**, sans erreur visible nulle part.
- `adminEmail()` retombe désormais sur `ADMIN_EMAIL`, la variable que
  l'installateur du serveur a de toute façon renseignée, quand le réglage est
  vide ou porte encore la valeur semée. À défaut des deux, elle retourne `null`
  et `sendToAdmin()` s'abstient, ce qui est honnête : ne pas envoyer vaut mieux
  qu'envoyer vers une adresse qui rebondit.

#### `site_url` hors requête ne connaissait que le placeholder
- `Context::siteUrl()` savait déjà ignorer le `http://localhost` semé et prendre
  l'origine de la requête, ce qui rend les balises canoniques correctes sur un
  site en ligne. Mais hors requête, une commande console ou un worker Messenger,
  il retournait le placeholder faute de mieux.
- Il consulte maintenant le contexte de routage, que le framework remplit depuis
  `DEFAULT_URI`. Un sitemap ou une balise canonique rendus par le worker nomment
  donc l'hôte que le déploiement a déjà déclaré. Le placeholder ne subsiste que
  si rien n'est configuré nulle part.

#### Les deux paramètres sont semés vides
- `site_url` et `backend_email` arrivaient avec `http://localhost` et
  `admin@aurora.app`. Une valeur plausible affichée dans l'écran de réglages se
  lit comme un choix déjà fait : personne ne la corrige. Vide, le champ dit ce
  qu'il est, et les deux méthodes ci-dessus savent quoi en faire.
- Les installations existantes ne sont pas touchées en base : les anciennes
  valeurs sont reconnues comme des placeholders par le code, donc corrigées de
  fait sans migration.

### Dans aurora-client

`make aurora-update` suffit. Aucune action en base n'est nécessaire : une
installation qui porte encore `admin@aurora.app` ou `http://localhost` se met à
utiliser `ADMIN_EMAIL` et `DEFAULT_URI` dès la mise à jour.

Vérifiez tout de même que `ADMIN_EMAIL` pointe sur une adresse réelle dans le
`.env.local` du serveur : c'est elle qui reçoit désormais les notifications si
le réglage n'a jamais été renseigné.

## [0.9.8] - 2026-08-30

### Corrigé

#### Les permissions documentées cassaient le premier déploiement
- Le §6 du guide de déploiement prescrivait `chown -R www-data:www-data var/`
  puis `chmod g+rX`. Or `deploy-prod` tourne sous un compte humain et écrit dans
  `var/cache` via `cache:clear` : en suivant la doc à la lettre, le déploiement
  échoue dès `make cc-prod`. Cette recette n'est correcte que si le déploiement
  s'exécute **en tant que** `www-data`, ce que le Makefile ne fait pas.
- Le §6 décrit maintenant le montage qui tient : propriétaire au déployeur,
  groupe `www-data`, setgid sur les répertoires de `var/`, `umask 0002`. Il
  ajoute que `.env.local` doit rester lisible par `www-data` : en `600`, PHP-FPM
  ne le lit plus et Symfony retombe silencieusement sur les valeurs de `.env`.

#### L'OPcache était présenté comme un absolu
- « reset après chaque déploiement » ne vaut que si
  `opcache.validate_timestamps=0`. Avec le réglage par défaut des paquets Debian
  et Ubuntu (`On`, `revalidate_freq=2`), PHP reprend les fichiers modifiés tout
  seul et le reset ne sert à rien. Énoncé sans condition, il pousse à ajouter au
  déploiement un `systemctl reload php8.4-fpm`, donc une exigence de root
  souvent inutile. Le §7 distingue désormais les deux cas et donne la commande
  pour savoir dans lequel on est.

#### Versions de PostgreSQL périmées
- `joining_a_project.md` proposait `serverVersion=16` et le README du template
  annonçait « Postgres 16 », alors que tout le reste du projet est sur 18.

#### §10 décrivait une séquence que `deploy-prod` fait désormais lui-même
- Remplacée par `git checkout <tag> && make deploy-prod`, avec le renvoi vers
  §7 pour le seul cas où un reload de PHP-FPM reste nécessaire.

### Ajouté

#### `server_provisioning.md` : d'une machine nue à l'application servie
- La doc de déploiement partait d'un serveur déjà provisionné, sans dire comment
  y arriver. Le nouveau document couvre les paquets, la création du rôle et de
  la base PostgreSQL (le rôle applicatif n'a besoin ni de `SUPERUSER` ni de
  `CREATEDB`), le modèle de permissions, le vhost Apache complet, et HTTPS.
- **HTTPS n'était mentionné nulle part** dans les 68 fichiers de doc, alors que
  le guide fait poser `DEFAULT_URI=https://…`. Le document explique certbot et
  surtout ce qu'il fait au vhost : il en recopie une version 443, modifie
  l'original pour la redirection, et le fichier d'origine cesse donc de décrire
  l'état réel du serveur.
- Le vhost documenté pose `Options +FollowSymLinks`, ce qui n'est pas
  décoratif : `public/build` est un lien symbolique vers
  `vendor/axelraboit/aurora/public/build`. Le bloc `<Directory /var/www/>`
  livré par Ubuntu l'active par défaut, mais tout durcissement qui pose
  `-FollowSymLinks` renvoie 403 sur la totalité des assets Vite sans que rien
  n'explique pourquoi.

#### §11 Sauvegardes
- La doc avertissait en gras que `AURORA_MOUNT_POINT_KEY` ne doit jamais changer
  sous peine de rendre les MountPoints illisibles, sans jamais dire de
  sauvegarder le fichier qui la contient. Le §11 liste les trois choses qui ne
  se reconstruisent pas depuis git (`.env.local`, la base, `var/uploads/`), et
  insiste sur les deux points qui distinguent une sauvegarde d'un fichier qui
  grossit : vérifier le dump à la production avec `pg_restore --list`, et avoir
  testé une restauration réelle au moins une fois.

### Dans aurora-client

`make aurora-update` récupère le README du template (mention PostgreSQL). La
doc, elle, est livrée avec le paquet : elle est à jour dans
`vendor/axelraboit/aurora/docs/` dès la mise à jour.

Si un serveur existant a été monté en suivant l'ancien §6, vérifiez que le
compte qui déploie peut écrire dans `var/` : `deploy-prod` échouerait sinon dès
le cache prod.

## [0.9.7] - 2026-08-30

### Corrigé

#### Dernier reste de français dans le `Makefile`
- La cible `tag`, dépréciée, affichait son explication en français. C'était le
  dernier endroit du fichier à s'écarter de l'anglais, la 0.9.6 ayant traité
  les trois cibles ajoutées en 0.9.5. Les messages `echo` du template sont
  désormais homogènes de bout en bout.

### Dans aurora-client

Rien à faire au-delà de `make aurora-update`, qui récupère le `Makefile` par
`sync-makefile`. Aucun comportement ne change : `make tag` refuse toujours de
s'exécuter et renvoie vers la publication depuis `master`.

## [0.9.6] - 2026-08-30

### Corrigé

#### Les cibles ajoutées en 0.9.5 parlaient français
- `worker-stop`, `worker-start` et `deploy-check` sortaient leurs messages en
  français, alors que le `Makefile` est en anglais de bout en bout : aide des
  cibles, commentaires de recette et messages `echo` (`✅ Runtime directories
  created`, `❌ Refused: target is destructive`, `⚠️ aurora-core's nested
  vendor/ is missing`). Tout est repassé en anglais, commentaires compris.

```
🔎 Post-deploy checks
  ✅ deployed version: v0.1.14
  ✅ the application boots in prod
  ✅ no pending migration
  ✅ worker aurora-worker is active (started Sun 2026-08-30 13:10:22 UTC)
  ✅ no failed message
  ✅ https://app.example.com answers 200
✅ All green.
```

> À noter, hors périmètre de cette version : la cible `tag` est le seul autre
> endroit du `Makefile` dont les messages sont en français. Elle est
> dépréciée et ne fait qu'afficher une explication, donc rien ne presse, mais
> c'est la dernière exception.

## [0.9.5] - 2026-08-30

### Ajouté

#### Le worker est arrêté pendant le déploiement, et relancé après
- `deploy-prod` arrête le worker Messenger avant de toucher au code, et le
  relance une fois le cache prod reconstruit. Sans ça le worker consomme des
  messages pendant que `vendor/` et `var/cache/` sont à moitié remplacés, et il
  garde son ancien code en mémoire après coup : un changement de `.env.local` ou
  de code ne l'atteignait qu'à son `--time-limit`, une heure plus tard.
- Un `trap` relance le worker même si le déploiement échoue en cours de route.
  Un déploiement interrompu qui laisse le worker éteint est pire que pas de
  déploiement du tout : la file se remplit en silence.
- Nouvelles cibles `worker-stop` et `worker-start`, pilotées par la variable
  `WORKER_SERVICE` (défaut `aurora-worker`). Elles ne font rien s'il n'y a pas
  de systemd, si l'unité n'existe pas, ou si la variable est vidée, ce qui
  garde le template agnostique de l'infra. Le nom se surcharge dans
  `Makefile.local`.

#### `make deploy-check`
- Nouvelle cible, lancée à la fin d'`install-prod` et de `deploy-prod`, qui dit
  ce qui va et ce qui ne va pas plutôt que d'afficher un `✅ Deployed` de
  principe :

```
🔎 Vérifications post-déploiement
  ✅ version déployée : v0.1.13
  ✅ l'application boote en prod
  ✅ aucune migration en attente
  ✅ worker aurora-worker actif (démarré Sun 2026-08-30 14:52:01 UTC)
  ✅ aucun message en échec
  ✅ https://app.example.com répond 200
✅ Tout est vert.
```

- Chaque ligne dégrade proprement : `➖` quand la vérification ne s'applique pas
  (pas de systemd, pas de `DEFAULT_URI`, pas de `curl`) plutôt qu'un faux
  négatif. La cible sort en 1 si au moins une vérification échoue.
- Elle est utilisable seule, à tout moment, pour savoir dans quel état est un
  serveur.

### Dans aurora-client

`make aurora-update` récupère le `Makefile` corrigé via `sync-makefile`.

`worker-stop` et `worker-start` appellent `sudo systemctl`. Sur un serveur où
le `sudo` de l'utilisateur de déploiement demande un mot de passe, le
déploiement le réclamera deux fois. Pour un déploiement non interactif, poser
une règle sudoers limitée à ce seul service :

```
# /etc/sudoers.d/aurora-worker
<user> ALL=(root) NOPASSWD: /usr/bin/systemctl start aurora-worker, /usr/bin/systemctl stop aurora-worker, /usr/bin/systemctl restart aurora-worker
```

## [0.9.4] - 2026-08-30

### Corrigé

#### `make deploy-prod` cassait la build et mentait sur le résultat
- La cible inlinait `pnpm --dir=vendor/axelraboit/aurora run build` au lieu
  d'appeler `make build`, et perdait au passage `$(AURORA_ENV)`, donc
  `AURORA_CLIENT_DIR`. C'est la variable que le hook `prebuild` d'aurora-core
  (`bin/dump-translations`) lit pour savoir quelle console lancer : sans elle il
  lance **celle d'aurora-core**, qui boote son propre kernel depuis son vendor
  imbriqué installé en `--no-dev`. La build mourait sur
  `Class "Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle" not found`,
  et les traductions JS des modules du client n'étaient de toute façon jamais
  dumpées.
- Les étapes étaient chaînées par `;`, donc l'échec n'arrêtait rien : le
  déploiement continuait et affichait `✅ Deployed vX.Y.Z`. Un `set -e` ouvre
  maintenant la séquence.
- `deploy-prod` passe par `make build-prod` et `make cc-prod` plutôt que de
  réécrire ces étapes à la main. C'était la cause racine : une copie qui dérive
  de l'original.

#### `install-prod` installait les linters sur le serveur de prod
- `make build` dépend d'`aurora-vendor-guard`, qui restaure les outils de lint
  d'aurora-core (php-cs-fixer, phpstan, rector, twig-cs-fixer) quand il ne les
  trouve pas. Sur un serveur neuf il ne les trouve jamais : le premier
  `install-prod` les installait tous.
- Nouvelle cible `build-prod`, même build sans le guard. `install-prod` et
  `deploy-prod` l'utilisent. Le seul vendor dont la build a besoin, celui
  imbriqué d'aurora-core, est restauré par leur étape dédiée.
- Constaté sur un serveur de production : `vendor/axelraboit/aurora/tools/*/vendor`
  était peuplé sur le serveur.

### Dans aurora-client

`make aurora-update` récupère le `Makefile` corrigé via `sync-makefile`.

Sur un serveur déjà déployé, les linters d'aurora-core sont peut-être déjà
installés. Ils ne gênent pas, mais ils n'ont rien à y faire :

```bash
rm -rf vendor/axelraboit/aurora/tools/*/vendor
```

## [0.9.3] - 2026-08-30

### Corrigé

#### Les fixtures sortent de `src/`, ce que 0.9.2 n'avait fait qu'à moitié
- 0.9.2 excluait les `DataFixtures/` du driver attribut de Doctrine. Le
  premier déploiement en production a montré que ça ne suffisait pas : un second
  scanner autoload les mêmes fichiers, le loader de routes attributaires, via le
  `resource: '../vendor/axelraboit/aurora/src/' type: attribute` du
  `routes.yaml` client. `cache:clear --env=prod` mourait donc toujours sur
  `Class "Doctrine\Bundle\FixturesBundle\Fixture" not found`.
- Excluer scanner par scanner est une liste à maintenir à chaque nouveau
  mécanisme qui parcourt `src/`. Les fixtures déménagent donc dans `fixtures/`,
  hors de `src/`, avec le namespace `Aurora\Fixtures\<Module>` et son propre
  PSR-4. Aucun scanner ne visite ce répertoire : le problème disparaît par
  construction plutôt que par énumération.
- `ExcludeDataFixturesFromMappingPass`, introduit en 0.9.2, est retiré : il
  n'a plus rien à exclure.
- `PlanningDemoFixtures` n'était enregistrée par aucun bloc `when@dev:` ; elle
  ne tenait que par le glob `Aurora\:` sur `src/`. Le nouveau glob
  `Aurora\Fixtures\: '../fixtures/'` la déclare explicitement, avec les
  quatre autres.
- PHPStan et Rector analysaient `src/` : les deux sont recâblés sur `fixtures/`
  pour ne pas perdre la couverture au passage. Leur config étant partagée avec
  les projets clients, qui n'ont pas ce répertoire, le chemin est ajouté en CLI
  côté core pour PHPStan et filtré par `is_dir()` pour Rector.

### Dans aurora-client

Rien à faire si les fixtures du projet sont dans `src/DataFixtures/`, comme le
veut la convention : aucun scanner ne visite ce répertoire.

En revanche, **une fixture posée sous `src/Module/<X>/DataFixtures/` casse la
prod**. `config/routes.yaml` importe `../src/Module/` en `type: attribute` et
`doctrine.yaml` mappe le même répertoire ; les deux autoloadent chaque classe
qu'ils croisent. La déplacer vers `src/DataFixtures/`, ou la supprimer si elle
ne sert plus.

> ⚠️ Le même trou reste ouvert pour les modules extraits en paquets séparés :
> leur `DataFixtures/` vit dans le répertoire du paquet, et
> `AuroraModuleRouteLoader` importe ce répertoire entier en `type: attribute`.
> Aucun module n'est extrait aujourd'hui, mais le premier qui ship des fixtures
> reproduira le bug.

## [0.9.2] - 2026-08-30

### Ajouté

#### Mémoire : Resend est le transport mail de la prod
- `MAILER_DSN` en production passe par `resend+api://API_KEY@default`. Le pont
  `symfony/resend-mailer` est déjà dans le `require` d'aurora-core, donc il
  survit à `composer install --no-dev` : un serveur neuf n'a besoin que de la
  clé.
- Le piège documenté est le défaut `smtp://localhost:1025` de `.env` : sur un
  serveur dont le `.env.local` ne redéfinit pas la variable, l'application
  démarre sans erreur et n'envoie rien, sans alerte. La réinitialisation de mot
  de passe est cassée en silence.
- La mémoire note aussi qu'après un changement de DSN il faut redémarrer le
  worker Messenger en plus de recharger PHP-FPM : le worker a lu `.env.local` à
  son boot, et l'essentiel des mails passe par le transport `async`.
- Nouveau fichier `.claude/memory/aurora-client/convention_mailer_resend_prod.md`,
  indexé dans `.claude/memory/aurora-client/MEMORY.md`.

### Corrigé

#### Un `composer install --no-dev` ne bootait pas
- `PdfThumbnailGenerator` type-hinte `Symfony\Component\Process\ExecutableFinder`
  en service autowiré, mais `symfony/process` n'était déclaré nulle part. Le
  paquet n'arrivait que par ricochet, via le `require-dev` d'un projet client
  (`symfony/maker-bundle`). En prod il disparaissait, et la compilation du
  container mourait sur `Class "Symfony\Component\Process\ExecutableFinder"
  not found`. `symfony/process` passe en `require`.
- Les fixtures vivent sous `src/Core/DataFixtures/` et `src/Module/*/DataFixtures/`,
  donc à l'intérieur des répertoires que le driver Doctrine parcourt. Or
  `getAllClassNames()` autoload chaque fichier avant de demander si c'est une
  entité, et ces classes étendent le `Fixture` de doctrine-fixtures-bundle,
  absent en prod. `doctrine:schema:create` et tout warmup touchant aux métadonnées
  ORM tombaient sur `Class "Doctrine\Bundle\FixturesBundle\Fixture" not found`.
- `config/services.yaml` posait déjà ce garde-fou côté container (exclusion du
  glob `Aurora\`, ré-enregistrement sous `when@dev`) ; il manquait la moitié
  mapping. `ExcludeDataFixturesFromMappingPass` appelle désormais
  `addExcludePaths()` sur le driver attribut. Les fixtures ne portent aucun
  attribut ORM, donc l'exclusion ne coûte rien en dev non plus.
- Les deux bugs ne se voyaient qu'au premier déploiement d'un client sur un
  serveur neuf. Constatés au premier déploiement en production le 30/08/2026.

#### `make install-prod` ne passait pas sur un serveur neuf
- La cible enchaînait `composer install --no-dev` puis
  `pnpm --dir=vendor/axelraboit/aurora install`. Le premier ré-extrait
  aurora-core et efface au passage son `vendor/` imbriqué, celui que son
  `package.json` vise avec `"@symfony/ux-vue": "file:vendor/symfony/ux-vue/assets"`.
  Le pnpm mourait donc sur un `ENOENT` désignant un chemin que personne n'a
  écrit à la main. `make build` réparait déjà le cas via `aurora-vendor-guard`,
  mais il tourne après. Le template restaure maintenant le vendor imbriqué
  entre les deux, sans les linters qui n'ont rien à faire sur un serveur de
  prod. `deploy-prod` avait le même trou, il est corrigé aussi.
- La cible appelait ensuite `make migrate-f`, alors que sur une base vierge la
  chaîne de migrations plante : Doctrine Migrations 3.x traite les namespaces
  dans leur ordre de déclaration et non par version, donc une
  `ClientMigrations` qui étend une table core passe avant l'`AuroraMigrations`
  qui la crée. Nouvelle cible `db-install-prod` : `schema:create`, marquage de
  toutes les migrations comme appliquées, puis `messenger:setup-transports`
  (`messenger_messages` vient d'une migration qu'on vient de marquer sans la
  jouer, et le DSN porte `auto_setup=0`, donc rien d'autre ne créerait la
  table). `deploy-prod` garde `migrations:migrate` : sur un serveur déjà
  installé la chaîne est incrémentale et le problème d'ordre ne se pose pas.
- `docs/aurora-client/deployment/README.md` §1 est aligné sur la nouvelle
  séquence.

### Dans aurora-client

`make aurora-update` suffit : il lance `sync-makefile`, qui recopie le template
corrigé sur le `Makefile` du projet. La mémoire arrive par le même chemin, via
son symlink. Les deux correctifs de dépendances sont internes au bundle.

Une réserve sur `sync-makefile` : il refuse d'écraser un `Makefile` qui porte
des modifications non commitées. Si c'est le cas, commiter ou déplacer les
cibles custom dans `Makefile.local` avant de lancer la mise à jour.

Si un projet a contourné l'un des deux bugs de packaging en ajoutant
`symfony/process` ou `doctrine/doctrine-fixtures-bundle` au `require` de son
`composer.json`, les deux lignes peuvent être retirées après
`make aurora-update`.

## [0.9.1] - 2026-08-30

### Corrigé

#### La suite de tests écrivait dans le vrai dossier d'uploads
- `app.upload_dir` avait la même valeur dans tous les environnements : les
  tests d'intégration déposaient leurs uploads dans `var/uploads`, celui que
  l'installation du développeur sert, et rien ne les retirait. 1184
  `pixel-*.png` et leurs variantes s'y étaient accumulés depuis août.
- On ne l'a vu que parce que `aurora:ged:prune-orphans`, sorti en 0.9.0, les a
  signalés : la commande écrite pour trouver les orphelins rapportait les
  déchets de la suite de tests.
- L'environnement de test écrit désormais dans `var/test-uploads`, vidé avant
  chaque classe. La surcharge est dans `config/services_test.yaml` et non
  `config/packages/test/` : MicroKernelTrait importe `config/services.yaml`
  **après** `config/packages/{env}/`, donc une surcharge placée là est lue puis
  aussitôt écrasée par la valeur par défaut. Le vidage vérifie le chemin avant
  d'effacer.

### Dans aurora-client

Rien à faire, sauf si le projet a ses propres tests d'intégration qui uploadent :
dans ce cas, copier `config/services_test.yaml`.

## [0.9.0] - 2026-08-30

### Ajouté

#### La colonne Aperçu ouvre la modale de prévisualisation
- En mode grille, cliquer une carte ouvrait déjà la modale du document. En
  mode liste, la colonne Aperçu montrait la même vignette et ne faisait rien.
- La cellule est maintenant un vrai bouton : accessible au clavier, anneau de
  focus, `aria-label` qui nomme le document. Elle respecte le mode sélection
  comme les cartes.

#### `aurora:ged:prune-orphans`
- Liste, et avec `--force` supprime, les fichiers de `var/uploads/ged/` qu'aucune
  ligne ne référence. Le correctif 0.8.1 arrête les nouveaux orphelins ; celui-ci
  s'occupe de ceux déjà présents.
- Refuse de toucher aux fichiers récents : l'endpoint d'upload écrit les octets
  avant la soumission du formulaire, donc un fichier sans ligne est peut-être
  simplement un formulaire ouvert. `--days` vaut 7 par défaut.
- À blanc par défaut ; rien n'est supprimé sans `--force`.

### Modifié

- Les fixtures de démonstration n'embarquent plus de photographies
  personnelles : `me.jpg` et `previous_job.jpg` sont remplacées par deux
  dégradés générés aux mêmes dimensions.

### Dans aurora-client

Pour récupérer l'espace disque des anciennes suppressions :

```bash
php bin/console aurora:ged:prune-orphans          # liste, ne supprime rien
php bin/console aurora:ged:prune-orphans --force  # supprime
```

## [0.8.2] - 2026-08-30

### Ajouté

- Un test d'intégration qui supprime un vrai document, avec ses vrais fichiers
  et une vraie ligne de version, contre le schéma réel. Les tests de 0.8.1
  couvraient la logique et les deux requêtes de garde, mais aucun n'exerçait
  le `ON DELETE CASCADE` - c'est-à-dire précisément ce qui rendait les
  fichiers de version irrécupérables.

### Dans aurora-client

Rien à faire.

## [0.8.1] - 2026-08-30

### Corrigé

#### Supprimer un document GED laissait ses fichiers sur le disque
- `DocumentManager::delete()` et `bulkDelete()` effacent maintenant le fichier
  du document, sa vignette générée, et le fichier de chacune de ses versions.
  Seules les variantes d'image étaient effacées ; tout le reste restait sur le
  disque indéfiniment. Les lignes de version disparaissant par un
  `ON DELETE CASCADE`, plus aucune ligne ne nommait ces fichiers : ils étaient
  irrécupérables autrement qu'à la main.
- Un garde-fou précède chaque effacement : un chemin encore référencé par une
  ligne survivante - document ou version - n'est pas touché. Ce n'est pas
  théorique, `recordVersion()` fait délibérément pointer une ligne de version
  sur le `filePath` du document vivant.
- L'effacement passe après le `flush` : un `flush` en échec ne coûte plus ses
  octets à personne.
- Neuf tests, dont trois d'intégration qui exécutent les deux requêtes de
  garde contre le schéma réel.

### Dans aurora-client

Rien à faire. Les fichiers déjà orphelins d'anciennes suppressions restent sur
le disque : ils ne sont plus référencés nulle part et se retirent à la main
dans `var/uploads/ged/`.

## [0.8.0] - 2026-08-30

### Ajouté

#### Premier compte d'une installation
- `aurora:user:create --dev` donne `ROLE_DEV`, à côté de `--admin` qui reste.
  `/dev` étant gardé par `ROLE_DEV`, un propriétaire créé avec `--admin` - ce
  que `aurora:install` recommandait - ne pouvait pas ouvrir le tableau de bord
  qui active les modules sur son propre site. Les deux options passées
  ensemble donnent `ROLE_DEV`, la plus large.
- `aurora:install` recommande désormais `--dev`.
- Quatre tests sur cette commande, qui n'en avait aucun.

### Dans aurora-client

Rien à faire. Sur une installation existante, un compte déjà créé en
administrateur se corrige avec :

```bash
php bin/console aurora:user:role <email> ROLE_DEV
php bin/console aurora:user:role <email> ROLE_ADMIN --remove
```

## [0.7.4] - 2026-08-29

### Corrigé

#### On ne pouvait créer le premier élément de rien

Sur quatre écrans, le bouton de l'état vide ne faisait rien : les modales
vivaient dans la branche `v-else` que cet état vide remplace, donc elles
n'existaient pas tant que la liste était vide. Le bouton levait son drapeau,
rien ne l'écoutait.

Seulement le premier élément était concerné : dès qu'un existe, la branche se
rend et tout fonctionne. C'est pourquoi ça a survécu - le défaut est invisible
sur toute base contenant déjà des données.

| Écran | Ce qui était impossible |
|---|---|
| Formulaires | créer le premier formulaire |
| Types de publication | créer le premier type |
| Taxonomies | créer la première taxonomie |
| Dossiers GED | créer le premier dossier |

### Ajouté

- `EmptyStateCanReachItsModalTest` : un état vide dont le bouton ouvre une
  modale absente de l'arbre fait échouer la CI.
- `FormsApp.test.js` : le composant monte sa modale même sans aucun formulaire.

### Dans aurora-client

Rien à faire.

## [0.7.3] - 2026-08-29

### Corrigé

#### Notes
- **Le partage d'une note échouait.** Les quatre chemins étaient produits par
  le générateur de vue, jamais passés par le gabarit, jamais déclarés par le
  composant : le front appelait une URL indéfinie. L'aperçu des notes liées
  restait vide pour la même raison.

#### GED
- **Créer, renommer, supprimer et déplacer un dossier depuis la barre latérale
  des documents ne faisait rien**, ainsi que le déplacement groupé. Même cause,
  trouvée par le garde-fou ajouté pour la précédente. Les props ont un défaut
  vide, donc Vue n'avertissait de rien.

#### Thèmes
- L'éditeur de thème proposait encore l'ancienne couleur indigo alors que
  l'application est verte : deux copies JS du défaut, dont une seule avait
  bougé.

### Ajouté

#### Garde-fous
- `ViewBuilderPropsReachTheTemplateTest` : une clé produite par un générateur
  de vue et jamais transmise fait échouer la CI.
- `ThemeDefaultColourMirrorTest` : la couleur par défaut doit être identique
  en PHP et dans les deux écrans de thème.

### Dans aurora-client

Rien à faire.

## [0.7.2] - 2026-08-29

### Changé

#### Accessibilité du frontend
- Une carte de publication placée dans la grille d'une page était un `<a>`
  enveloppant tout son contenu : un lecteur d'écran annonçait la vignette, le
  titre et le résumé comme un seul nom de lien. Le lien n'entoure plus que le
  titre et étire une surcouche sur la carte - toute la surface reste cliquable,
  le nom se limite au titre. Même montage que les cartes de liste.

### Dans aurora-client

Rien à faire.

## [0.7.1] - 2026-08-29

### Changé

#### Frontend
- Une carte de publication placée dans la grille d'une page survolait
  différemment de la carte identique des listes : bordure grise au lieu de
  foncer, titre vers la couleur du texte au lieu de l'accent, et aucun fond.
  Les deux se comportent désormais pareil.

#### Notes
- Le cadre de l'éditeur markdown est rétabli, ainsi que celui du champ
  d'étiquettes.

### Dans aurora-client

Rien à faire.

## [0.7.0] - 2026-08-29

Mineure et non patch : la couleur d'accentuation par défaut change, ce qui se
voit sur toute l'application d'un client qui n'a pas défini la sienne.

### Changé

#### Identité visuelle
- **La couleur d'accentuation par défaut passe de l'indigo au vert** (emerald,
  graine `#10b981`). Mesuré avant de choisir : le générateur de palette fige la
  luminosité de chaque palier, donc la teinte bouge sans emporter le contraste
  (blanc sur `accent-600` : 5,52:1 contre 6,76:1 auparavant, tous deux au-delà
  des 4,5:1 requis). L'indigo en dur des gabarits d'e-mail suit.
- **Le crédit du pied de page devient le copyright de l'application** :
  `© {année} {nom du site}`, lu depuis le réglage `site_name`. Il affichait
  « Propulsé par Aurora · © axelraboit » avec un lien vers le GitHub de
  l'auteur, ce qui crédite le mauvais produit sur le déploiement d'un client.

#### Notes
- Le mode scindé n'affichait que l'éditeur sur une fenêtre étroite : le volet
  prenait une largeur figée en pixels qui ne tenait plus une fois le menu et
  l'arborescence servis. Il est désormais plafonné à une part de la place
  disponible, et s'empile verticalement sous `md`.
- L'éditeur markdown n'a plus de cadre : il remplit son volet, dont les bords
  disent déjà où il commence.
- L'arborescence portait un `z-index` à toutes les tailles alors qu'elle n'en a
  besoin qu'en tiroir mobile ; elle passait par-dessus le fil d'Ariane au
  défilement.

### Ajouté

#### Garde-fous
- `SidemenuSectionThemeTest` : une section de menu sans couleur fait échouer la
  CI. `planning` et `notes` retombaient sur la palette d'accentuation et
  paraissaient oubliées.
- `StickyHeaderStackingTest` : un panneau qui garde son `z-index` au-delà du
  seuil où il cesse de flotter fait échouer la CI.
- `ClientReadmeLinksTest` : chaque chemin cité par le README client doit
  exister.

### Dans aurora-client

**Bumper la contrainte** dans `composer.json`, `^0.6` ne prend pas les 0.7 :

```bash
composer require axelraboit/aurora:^0.7
make aurora-update && make ft
```

Si le projet a défini sa propre couleur primaire dans un thème, rien ne change
visuellement. Sinon, l'application passe au vert. Pour rester en indigo,
renseigner `#6366f1` comme couleur primaire dans les réglages du thème.

## [0.6.2] - 2026-08-29

### Changé

#### Releases
- Le commentaire de `tag-guard.yml` disait que l'attente d'une minute évitait
  une fausse alerte sur les releases automatiques. C'est faux : GitHub ne
  déclenche aucun workflow sur un événement produit par le `GITHUB_TOKEN` par
  défaut, donc le garde-fou ne voit jamais ces tags. Il ne surveille que les
  tags poussés à la main, ce qui est précisément son rôle.

### Dans aurora-client

Rien à faire.

## [0.6.1] - 2026-08-29

### Changé

#### Releases
- `make tag` refuse désormais et affiche le flux. Il créait un tag sans
  release et sans préfixe `v` : Composer l'aurait proposé aux clients comme
  une version publiée, jamais passée par `master` ni par la CI.
- Nouveau garde-fou `.github/workflows/tag-guard.yml` : tout tag `v*` poussé
  sans release accompagnante fait échouer la CI, avec les deux issues
  (publier la release, ou supprimer le tag).

#### Documentation
- La doc de propagation, le skill `ship` et deux docs client décrivaient
  encore les releases taguées comme « plus tard, pas maintenant ». Elles
  décrivent le flux courant.
- La mémoire `project_notes_share_link_read_only`, citée par trois
  commentaires du code de partage, n'existait pas. Elle existe.

### Dans aurora-client

Rien à faire. `make tag` disparaît du Makefile client au prochain
`make aurora-update` ; s'il figurait dans un script de déploiement, le
remplacer par un merge `develop` -> `master`.

## [0.6.0] - 2026-08-29

> Première version publiée par le workflow `master`. Le changelog s'arrêtait
> à 0.3.0 ; les 0.4 et 0.5 n'ont jamais été taguées mais existent dans les
> migrations et la documentation (`MIGRATION_0.4.md`, « depuis 0.5 » dans le
> Makefile), d'où la reprise à 0.6.0 plutôt qu'à 0.4.0.

### ⚠️ Cassant - root `templates/` éliminé (sauf `bundles/`), tout sous `src/`

Le dossier `templates/` à la racine du bundle est éliminé. Tous les templates
sont désormais co-localisés sous `src/`, en miroir du refactor `assets/` :

| Avant | Après |
|---|---|
| `templates/Module/<X>/` | `src/Module/<X>/templates/` |
| `templates/Core/` | `src/Core/templates/Core/` |
| `templates/Shared/` | `src/Core/templates/Shared/` |
| `templates/Frontend/themes/default/` | `src/Core/templates/Frontend/themes/default/` |

**Seule exception** : `templates/bundles/TwigBundle/` reste à la racine du
projet - c'est une convention Symfony hardcodée dans `FilesystemLoader` pour
les overrides de templates de bundles tiers (error pages, …). Non négociable.

**Namespaces Twig inchangés côté API** : `@Editorial`, `@Crm`, `@Platform`,
`@Core`, `@Shared` etc. continuent de résoudre vers les bons emplacements.
Aucun `render(…)` ni `include`/`extends` n'est à modifier. Les références
sans namespace (`Frontend/themes/default/layout.html.twig`) résolvent toujours
via le null namespace, qui pointe désormais à la fois sur `src/Core/templates/`
(emplacement des templates bundle) et `templates/` (encore présent pour
`bundles/TwigBundle/` + overrides client à la racine du projet).

**Côté client** : compatibilité ascendante pour les trois familles.
Pour `@<Module>`, `@Core`, `@Shared`, `AuroraBundle::prependExtension` reconnaît
deux paths d'override (le nouveau co-localisé + le legacy top-level) :

| Namespace | Nouveau path client (recommandé) | Legacy path client (backward compat) |
|---|---|---|
| `@<Module>` | `<client>/src/Module/<X>/templates/` | `<client>/templates/Module/<X>/` |
| `@Core` | `<client>/src/Core/templates/Core/` | `<client>/templates/Core/` |
| `@Shared` | `<client>/src/Core/templates/Shared/` | `<client>/templates/Shared/` |

Pour les thèmes frontend custom : `<client>/templates/Frontend/themes/<slug>/`
**reste la convention canonique** (les thèmes sont de la data côté client,
pas du code de module). `ThemeManager.countTemplates()` accepte aussi
`<client>/src/Core/templates/Frontend/themes/<slug>/` en fallback (pour le
default theme livré par Aurora en mode core dev).

Aucune migration Doctrine ; clear cache + rebuild suffit.

### ⚠️ Cassant - root `assets/` supprimé, JS/Vue co-localisé sous `src/`

Le dossier `assets/` à la racine du repo a été éliminé. Tout le JS/Vue/CSS
est désormais co-localisé sous `src/`, en miroir de la structure PHP :

| Avant | Après |
|---|---|
| `assets/Module/<X>/...` | `src/Module/<X>/assets/...` |
| `assets/Core/backend/...` | `src/Core/Frontend/backend/...` |
| `assets/Core/frontend/...` | `src/Core/Frontend/frontend/...` |
| `assets/Core/utils/...` | `src/Core/Frontend/utils/...` |
| `assets/shared/...` | `src/Core/Frontend/shared/...` |
| `assets/locales/generated/...` | `src/Core/Frontend/locales/generated/...` |
| `assets/css/...` (sauf modules) | `src/Core/Frontend/css/...` |
| `assets/css/modules/notes/markdown/preview.css` | `src/Module/Notes/assets/backend/markdown/components/preview.css` |
| `assets/css/modules/editorial/prose.css` | `src/Module/Editorial/assets/backend/posts/prose.css` |
| `assets/css/core/sidemenu.css` | `src/Core/Frontend/backend/sidemenu/sidemenu.css` |
| `assets/controllers/` | `src/Core/Frontend/stimulus/` (renommé pour éviter le clash avec `Controller/` PHP) |
| `assets/controllers.json` | `src/Core/Frontend/stimulus.json` (override Symfony : `config/packages/stimulus.yaml`) |
| `assets/tests/` | `src/Core/Frontend/tests/` |
| `assets/.client-fallback/` | `src/Core/Frontend/.client-fallback/` |
| `assets/{app,flash,theme,guest,i18n,stimulus_bootstrap}.js` | `src/Core/Frontend/{app,flash,theme,guest,i18n,stimulus_bootstrap}.js` |

**Aliases Vite inchangés côté API** : `@vault`, `@editorial`, `@platform`,
`@configuration`, `@media`, `@general`, `@dev` etc. pointent toujours vers
les bons emplacements (`src/Module/<X>/assets/`). `@core`, `@`, `@shared`
résolvent sous `src/Core/Frontend/`. Les imports `@/css/...` ont été
remplacés par des chemins relatifs (`./preview.css`, `./sidemenu.css`)
car les CSS sont désormais co-localisés avec leur SFC.

**Stimulus** : le folder a été renommé pour éviter la confusion avec les
controllers PHP. La convention par défaut Symfony (`assets/controllers/`)
est overridée via `config/packages/stimulus.yaml`.

**Aucune migration Doctrine** ; côté front, rebuild Vite suffit.

Voir [`MIGRATION_0.4.md`](docs/aurora-client/MIGRATION_0.4.md) pour la note
détaillée côté client (rien ne change pour `aurora-client/assets/client/`).

### ⚠️ Cassant - namespaces Core déplacés sous leur module parent

Alignement de `src/Core/` sur la convention Vault-style déjà en place
côté `src/Module/` : les sous-modules Core vivent désormais dans un
sous-dossier de leur module parent (`Aurora\Core\Platform\User`,
`Aurora\Core\Configuration\Setting`, etc.). Voir
[`MIGRATION_0.4.md`](docs/aurora-client/MIGRATION_0.4.md) pour la table
de correspondance + le `sed` bulk.

| Avant | Après |
|---|---|
| `Aurora\Core\Dashboard\*` | `Aurora\Core\General\Dashboard\*` |
| `Aurora\Core\Profile\*` | `Aurora\Core\General\Profile\*` |
| `Aurora\Core\Search\*` | `Aurora\Core\General\Search\*` |
| `Aurora\Core\Audit\*` | `Aurora\Core\Dev\Audit\*` |
| `Aurora\Core\Setting\*` | `Aurora\Core\Configuration\Setting\*` |
| `Aurora\Core\Theme\*` | `Aurora\Core\Configuration\Theme\*` |
| `Aurora\Core\Media\*` | `Aurora\Core\Media\Library\*` |
| `Aurora\Core\User\*` | `Aurora\Core\Platform\User\*` |
| `Aurora\Core\Agency\*` | `Aurora\Core\Platform\Agency\*` |
| `Aurora\Core\Auth\*` | `Aurora\Core\Platform\Auth\*` |
| `Aurora\Core\Service\{Entity,Dto,Manager,Repository,Serializer,Controller,View}\*` | `Aurora\Core\Platform\Service\{...}\*` |
| `Aurora\Core\Service\{Platform,Media,Configuration,General}Context` | `Aurora\Core\{Platform,Media,Configuration,General}\{Same}Context` (racine du folder du module) |
| `Aurora\Module\<X>\Service\<X>Context` (12 business modules) | `Aurora\Module\<X>\<X>Context` (racine du folder du module) |
| `Aurora\Core\Menu\*` | `Aurora\Module\Editorial\Menu\*` (Menu = sous-module d'Editorial) |
| `Aurora\Core\MountPoint\*` | `Aurora\Module\Dev\MountPoint\*` |
| `Aurora\Core\Platform\*` | `Aurora\Module\Platform\*` (promotion Core → Module) |
| `Aurora\Core\Configuration\*` | `Aurora\Module\Configuration\*` |
| `Aurora\Core\Media\*` | `Aurora\Module\Media\*` |
| `Aurora\Core\General\*` | `Aurora\Module\General\*` |
| `Aurora\Core\Dev\*` | `Aurora\Module\Dev\*` |
| `Aurora\Core\{Platform,Configuration,Media,General,Dev}Module` | `Aurora\Module\<X>\<X>Module` |

**2e vague - templates + assets** : `templates/Core/backend/<X>/` et
`assets/Core/backend/<X>/` ont aussi été déplacés vers les modules promus
(`templates/Module/<NewModule>/backend/<X>/` et idem assets). 5 nouveaux
aliases Vite : `@platform`, `@configuration`, `@media`, `@general`, `@dev`.
Voir [MIGRATION_0.4.md](docs/aurora-client/MIGRATION_0.4.md) pour le sed bulk
côté client.

**Convention unique** : tout module (avec une entrée dans la sidemenu) vit
sous `src/Module/`. `src/Core/` ne contient plus **que** de l'infrastructure
cross-cutting (Encryption, Frontend, Locale, Mail, Notification, Module/Contract,
Repository, Scheduler, Sequence, Storage, Support, Twig, Validation, etc.).
Plus aucun `<X>Module.php` à la racine de `src/Core/`.

**Inchangé** (cross-cutting infra) : `Encryption`, `Frontend`, `Locale`,
`Mail`, `Menu`, `Migration`, `Module`, `MountPoint`, `Notification`,
`Repository`, `Scheduler`, `Sequence`, `Storage`, `Support`,
`Timestampable`, `Twig`, `Validation`.

**Aucune migration Doctrine** - les tables (`core_user`, `core_agency`,
`core_audit_log`, `core_media`, `core_setting`, etc.) gardent leur nom.

### ⚠️ Cassant - CLI wizards `aurora:make:module` + `aurora:make:entity` supprimés

Les deux commandes Symfony ajoutées plus tôt dans Unreleased ont été
**retirées**. Tout scaffolding passe désormais par les skills Claude
`/add-module` et `/add-entity`. Motivation : un dev pressé pouvait
exécuter le wizard CLI directement (`bin/console aurora:make:module
Loyalty`) et zapper les edits post-scaffold qui ne peuvent pas être
mécaniques :

- Patch sur `ModuleParameterEnum` (5 match arms à étendre côté core)
- Append sur `aliases.js` (côté core)
- Choix d'une icône Lucide pertinente (au lieu de `'flame'` par défaut)
- Polish des labels FR/EN (au lieu de `{{MODULE_LABEL}}`)
- Fleshing-out des fields sur `Abstract<Name>` (`make:entity`)

Le wizard CLI imprimait des hints textuels pour ces étapes - facilement
ignorés. Le skill Claude les exécute systématiquement, donc on supprime
l'entrée CLI pour fermer la porte aux dérives.

**Source de vérité unique** : les templates `.tpl` ont juste été
déplacés depuis `src/Core/Module/Command/templates/` vers
`.claude/skills/add-module/templates/` et `.claude/skills/add-entity/templates/`.
Le skill lit les `.tpl` via `Read`, substitue les `{{KEY}}` tokens, et
écrit le résultat via `Write` - aucune duplication.

**Migration** : si vous avez un script CI qui appelait
`bin/console aurora:make:*`, remplacez par une invocation Claude (par
ex. dans un agent CI), ou déclenchez le skill via le harness Claude
Code en mode batch.

### ⚠️ Cassant - `ApplicationParameterEnumInterface::getPlaceholder(): ?string`

Nouvelle méthode obligatoire sur l'interface. Tous les enums clients
implémentant `ApplicationParameterEnumInterface` (settings module) doivent
ajouter une implémentation par défaut :

```php
public function getPlaceholder(): ?string
{
    return null;
}
```

Override par case quand un exemple concret est plus parlant que la
description (`'INV-2026-000042'` pour un préfixe, `'admin@example.com'`
pour un email). Les 13 enums core ont déjà été migrés en interne.

Comportement runtime : si `getPlaceholder()` renvoie `null` ET que le
`defaultValue` du setting est non-trivial (non-vide, non-`'0'`),
`SettingsViewBuilder` utilise le défaut comme placeholder. Couvre la
mer de préfixes (`'INV'`, `'DEAL'`, `'ORD'`, …) et les défauts
Notes/Assistant (`'qwen3:8b'`, `'2048'`, …) sans wirage par-case.

### Dans aurora-client

Lancer après `make aurora-update` :

```bash
# 1. Déplacer les dossiers d'extension (Agency, User, …) sous Core/Platform/
git mv src/Module/Core/Agency src/Module/Core/Platform/Agency

# 2. Renommer les namespaces (sed bulk - voir MIGRATION_0.4.md pour la commande complète)
grep -rl 'Aurora\\Core\\Agency\\' src tests config | xargs sed -i 's|Aurora\\Core\\Agency\\|Aurora\\Core\\Platform\\Agency\\|g'

# 3. Ajouter getPlaceholder() sur les enums clients implémentant
#    ApplicationParameterEnumInterface (au minimum un `return null;`)
grep -rl "implements ApplicationParameterEnumInterface" src | xargs -I{} echo "Patch {} - add getPlaceholder(): ?string { return null; }"

# 4. (Optionnel) Câbler `placeholderKey: $case->getPlaceholder()` sur les
#    ConfigurationTabProvider clients pour forwarder les placeholders au
#    SettingFieldDescriptor.

# 5. Re-générer + valider
composer dump-autoload && make cc && make ft
```

Côté welding : `WeldingSettingEnum::getPlaceholder()` câblé avec 7 vrais
placeholders (`'WLD'`, `'WPDF'`, `'inspecteur@example.com'`, …) et
`WeldingModuleParameterEnum::getPlaceholder() => null` puisque les
toggles modules rendent en switch (pas d'input).

### Ajouté

#### Module Notes (Markdown, façon Obsidian)
- Nouveau module `Notes` et son sous-module `Markdown` : éditeur markdown,
  aperçu temps réel, liens `[[wiki]]` avec autocomplétion, backlinks et
  mentions non liées, graphe des connexions, callouts, commandes slash,
  arborescence, étiquettes, images collées et redimensionnables, sommaire,
  recherche. Rapatrié du paquet `aurora-notes`, archivé en août.
- Titre et contenu chiffrés au repos (`EncryptedTextType`). Conséquence à
  connaître : aucune recherche ni tri SQL possible sur ces colonnes, donc la
  recherche et le filtre par étiquette tournent en PHP, sur les notes d'une
  seule personne.
- Deux interrupteurs : `modules_notes_backend`, `modules_notes_markdown`.

#### Partage de note par lien (lecture seule)
- Un lien ouvre une note sans compte, avec échéance facultative et
  révocation. Sans destinataire c'est le « copier le lien » ; avec une
  adresse il part par mail et se révoque individuellement.
- Deux interrupteurs décident de ce qui accompagne la note : les sous-notes
  (arborescence) et les notes liées par `[[…]]`, suivies transitivement.
  L'écran liste les titres qui partiraient avant le clic, plutôt que de les
  compter.
- Un `[[lien]]` vers une note hors du partage devient du texte simple : un
  lien n'élargit jamais un partage tout seul.

#### Settings
- `ConfigurationTab::$moduleToggle` (`ModuleParameterEnum|string|null`) -
  cache l'onglet de `/backend/settings` quand le module est désactivé
  dans `/dev/dashboard/modules`. 5 tab providers core déjà câblés (Crm,
  Ecommerce, Notes, PersonalFinance, Assistant).
- `SettingFieldDescriptor::$placeholderKey` (`?string`) - clé i18n
  optionnelle pour le placeholder de l'input. `SettingsViewBuilder`
  traduit + transmet dans le payload Vue ; `SettingsApp.vue` consomme
  via `parameter.placeholder`. Si null + type `text`/`int`/`textarea`,
  fallback automatique sur `defaultValue` (couvre les ~20 préfixes
  sequences sans wirage par-case).
- 13 templates `.tpl` pour le scaffold entity 5 couches Sylius
  (Entity triplet + DTO quartet + Manager pair + Serializer pair +
  Repository + Controller) sous `.claude/skills/add-entity/templates/`.
  Le skill `/add-entity` les lit + applique les substitutions + patche
  `AuroraBundle::$resolve_target_entities` + flesh-out des fields.

#### Skills Claude
- `/audit-module-toggles` - audit read-only de tous les modules contre
  la convention toggle (20 critères : enum case, getToggles(), Context
  isBackendEnabled, NavSection gating, getCatalogNavSections unfiltered,
  sous-toggles, translations, ConfigurationTab.moduleToggle). Allowlist
  d'infra (Configuration / Platform / Dev / Media / General).
- Skills `/add-module` et `/add-entity` lisent désormais les templates
  `.tpl` co-localisés sous `.claude/skills/<skill>/templates/` et
  scaffoldent les fichiers directement (Read + substitution + Write),
  puis font les edits délicats (patch `ModuleParameterEnum`,
  `aliases.js`, fleshing-out AbstractX). Plus de CLI wizard
  intermédiaire - un seul point d'entrée, zéro risque de dérive.

#### Templates wizard
- `src/Core/Module/Command/templates/entity/*.tpl` (13 fichiers) - le
  pattern 5 couches Sylius vit là, plus en markdown dans le skill.
- `SettingEnum.php.tpl` et `ConfigurationTabProvider.php.tpl` du wizard
  `make:module` câblent désormais `getPlaceholder() => null` et
  `placeholderKey: $case->getPlaceholder()` + `moduleToggle:`
  context-aware (core enum case / client `BACKEND_KEY` / `null`).

### Changé

#### Publications
- Les cartes des listes publiques sont cliquables sur toute leur surface, et
  plus seulement sur le titre.

#### HTTP côté Vue
- Le `useRequest` **frontend** envoie enfin `X-Requested-With`. Il ne le
  faisait pas, alors que la convention l'affirmait : tous les appels des pages
  publiques partaient sans l'en-tête que Symfony lit pour répondre du JSON
  plutôt que du HTML. Cinq composables qui appelaient `fetch` à la main sont
  passés sur le wrapper partagé.

#### Menu latéral
- Les sections `planning` et `notes` ont leur propre couleur. Elles
  retombaient sur la palette d'accentuation et paraissaient oubliées à côté
  des six sections colorées.
- `extend-aurora-entity` skill : namespaces mis à jour
  (`Aurora\Module\Platform\Agency`, plus l'ancien `Aurora\Core\Agency`)
  + asset paths post-0.5 (`src/Module/<X>/assets/backend/`) + alias
  Vite par-module (`@platform/...` au lieu de `@aurora/Core/...`).
- `add-submodule` skill : asset paths post-0.5 alignés CORE+CLIENT.
- `check-extensibility` skill : check 17b (User-style `applyInput`
  absence légitime) + check 26 (audit des toggles de sous-modules).
- `make aurora-update` (Makefile distribué via `sync-makefile`) :
  enchaîne désormais `make translation && make build` à la fin pour
  régénérer le bundle Vite avec les i18n du nouveau core - plus de
  clés `backend.foo.bar` brutes affichées après bump.
- Commentaires aurora-core nettoyés de tous les exemples
  welding-internes (`WLD`, `modules_welding_backend`, `WeldingFoo`,
  etc.) - welding vit en client depuis 05e374ec, les exemples
  utilisent maintenant des valeurs neutres (`INV`,
  `modules_<module_id>_backend`, `MyEntity`).

---

## [0.3.0] - 2026-05-17

### Ajouté
- **Module Assistant IA** (Phase 1A + 1B) : chat synchrone avec un LLM local Ollama
  (qwen3:8b par défaut), tool-calling (`aurora_search`, `filesystem_read`,
  `filesystem_write`, `filesystem_search`, `image_read` via qwen2.5vl),
  mount-points configurables par utilisateur, flow de confirmation pour les
  actions destructives (write).
- **Onglet "Assistant" dans /backend/settings** : modèle chat, modèle vision,
  timeout HTTP, num_ctx, prompt système - tunables sans redéploiement (lecture DB
  avec fallback env).
- **`make sync-env`** + `bin/sync-client-env` : détecte les blocs
  `###> aurora/* ###` manquants dans `.env` et les insère au-dessus du divider
  CLIENT CUSTOM. Idempotent, valeurs existantes jamais touchées.
- **Divider `# === CLIENT CUSTOM ===`** dans `.env` aurora-client : sépare
  explicitement la zone gérée par aurora-core de la zone propriété du client.
- **`make sync-makefile` refusé** si Makefile a des edits non commités
  (`FORCE=1` pour forcer).
- **Tests** : +291 tests sur la période, total 2694.
- **`docs/aurora-shared/`** : nouveau dossier de docs transversales (form_validation,
  testing_php/vue, translations, scheduler, convention_seo_head) partagé entre
  aurora-core et aurora-client via vendor.
- **`docs/aurora-client/deployment/`** : guide principal + worker_systemd +
  apache_xsendfile + ocr_setup regroupés ici.
- **`docs/aurora-core/ops/prerequisites.md`** : checklist exhaustive des prérequis
  système, PHP, Ollama, vars d'env.

### Changé
- `Makefile` client : `README.md` n'est plus symlinké depuis le vendor - copié une
  seule fois à l'init, ensuite propriété du client.
- Docs : plus de symlinks `docs/aurora-*/` côté client - lecture directe dans
  `vendor/axelraboit/aurora/docs/`.
- Notes settings (Markdown + Block) : labels disambiguïsés
  ("Notes Markdown - Taille max…" vs "Notes Block - …").

### Dans aurora-client - à faire après `make aurora-update`

| Action | Commande / fichier |
|--------|-------------------|
| Ajouter les vars d'env `ASSISTANT_*` et `OCR_*` si absentes | `make sync-env` les ajoute automatiquement |
| Vérifier que `README.md` est bien un vrai fichier (plus un symlink) | `ls -la README.md` - si symlink, `make sync-claude-md` le remplace par une copie |
| Parcourir la section "CLIENT CUSTOM" de `.env` | `make sync-env` a ajouté le divider |

### Breaking changes
- Aucun changement d'API publique.

---

## [0.2.0] - 2026-05 (antérieur à ce changelog)

Établissement de la base : Posts avec éditeur bloc Editor.js, Notes Markdown
(wiki-links, graphe), Notes Block (EditorJS), Billing OCR (docTR + Ollama vision),
Galleries photo, Vault, Password Generator, extensibilité 5-couches Sylius sur 24
entités, conventions sync aurora-core → aurora-client (Makefile template, CLAUDE.md
symlink, jsconfig, security.yaml).

---

## [0.1.0] - avant 2026-05

Socle initial : Symfony 7 / PHP 8.4 / Vue 3 / Vite, modules Editorial CMS (Posts,
Taxonomies, Comments, Forms), CRM, ERP (Products), Ecommerce (Listings, Cart,
Orders), GED, HR, Planning, Project Management, auth (invitations, demandes
d'accès), thèmes, multi-langue.
