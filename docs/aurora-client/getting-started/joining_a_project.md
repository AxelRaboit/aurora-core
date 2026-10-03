# Rejoindre un projet aurora-client : installation locale

Tu viens de `git clone` un projet client Aurora (aurora-client ou un
projet dérivé) et tu veux le faire tourner en local. C'est **la**
procédure d'installation : le README du projet et les autres pages y
renvoient.

> 📘 Pour **créer un nouveau projet** à partir d'aurora-client, voir
> [`setup.md`](setup.md) section "Démarrer un nouveau projet". Ce doc-ci
> suppose que le projet existe déjà et que tu rejoins l'équipe.

---

## Quickstart

Prérequis installés (cf. [Prérequis](#prérequis)), PostgreSQL démarré :

```bash
git clone <url-du-projet> && cd <projet>
make setup-env          # crée .env.local avec APP_SECRET + clés Aurora générées
$EDITOR .env.local      # relis DATABASE_URL
$EDITOR .env.test.local # DATABASE_URL + les deux clés Aurora (cf. §3)
make install-dev        # deps + BDD neuve + fixtures, finit sur Vite (ne rend pas la main)
```

Puis, dans un deuxième terminal :

```bash
make start-d            # serveur Symfony en arrière-plan → https://127.0.0.1:8000
```

Et dans un troisième, si tu touches aux tâches de fond :

```bash
make start-dev-worker   # worker Messenger
```

Connexion : `dev@aurora.app` / `password` (compte des fixtures de dev,
rien d'autre). Ensuite `make ft` pour vérifier que tout est vert.

`make install-dev` lance lui-même tous les `composer install` et
`pnpm install` : tu n'as rien à installer à la main avant, c'est lui qui
crée `vendor/`.

---

## Prérequis

| Outil | Version | Vérifier |
|---|---|---|
| PHP | 8.4 | `php -v` |
| Composer | 2 | `composer --version` |
| Node.js | 24 (Vite 8 exige au moins 20.19 ou 22.12) | `node -v` |
| pnpm | 10 | `pnpm --version` |
| PostgreSQL | 18 (14+ toléré), qui tourne sur ta machine | `psql --version` |
| Symfony CLI | récente | `symfony version` |
| Git | avec un accès SSH à GitHub | `ssh -T git@github.com` |

Extensions PHP : `pdo_pgsql`, `intl`, `mbstring`, `sodium`, `gd`, `zip`,
`xml`, `ctype`, `iconv` (`exif` en option).

```bash
php -m | grep -iE "pdo_pgsql|intl|mbstring|sodium|gd|zip|xml|ctype|iconv|exif"
```

Quelques points qui font perdre du temps :

- **Le `php` du `PATH` doit être un 8.4.** Le Makefile appelle `php`
  (variable `PHP_BIN`), pas un binaire versionné.
- **pnpm via corepack** : `make pnpm-setup VERSION=10.x.y` active la
  version voulue.
- **L'utilisateur PostgreSQL doit pouvoir créer des bases.** `make
  install-dev` supprime puis recrée la base lui-même ; tu n'as pas à la
  créer à la main, mais le rôle de `DATABASE_URL` doit avoir le droit
  `CREATEDB`. Il n'y a pas de conteneur de base de données dans un
  projet client : PostgreSQL tourne directement sur ta machine.
- **Accès SSH à GitHub.** Le `composer.json` du projet déclare
  aurora-core comme dépôt VCS en SSH
  (`git@github.com:AxelRaboit/aurora-core.git`). Une installation
  depuis `composer.lock` passe sans clé, par l'archive zip publique ;
  en revanche `make aurora-update` (qui résout une nouvelle version) peut
  te demander une clé SSH ou un token GitHub.
- **Binaires optionnels** : `pdftoppm` (poppler) ou `ghostscript` pour
  les aperçus PDF de la médiathèque, `ffmpeg` pour les vignettes de
  vidéo. Sans eux, l'app tourne et affiche une icône à la place.
  L'extension `pcov` ne sert qu'à `make coverage`.

La référence unique des versions et des dépendances système est
[`../../aurora-core/ops/prerequisites.md`](../../aurora-core/ops/prerequisites.md).
En cas de doute, c'est elle qui fait foi.

---

## 1. Cloner

```bash
git clone <url-du-projet>
cd <projet>
```

---

## 2. Configurer `.env.local`

```bash
make setup-env
```

La cible copie `.env.local.example` vers `.env.local` et y génère
`APP_SECRET`, `AURORA_MOUNT_POINT_KEY` et `AURORA_ENCRYPTION_KEY`. Il te
reste à relire `DATABASE_URL` :

```dotenv
DATABASE_URL="postgresql://<user>:<password>@127.0.0.1:5432/<db_name>?serverVersion=18&charset=utf8"
```

Adapte `serverVersion` à la version de PostgreSQL qui tourne chez toi.

> ⚠️ `AURORA_ENCRYPTION_KEY` doit rester **stable** : si tu la changes
> après avoir saisi des données chiffrées, elles deviennent illisibles.
> Si l'équipe partage des données chiffrées (un dump de base, par
> exemple), demande-lui sa clé `AURORA_ENCRYPTION_KEY` au lieu de garder
> celle qui vient d'être générée.

---

## 3. Configurer `.env.test.local`

L'environnement de test **ne lit pas** `.env.local`. Crée
`.env.test.local` avec la connexion et les deux clés Aurora :

```dotenv
DATABASE_URL="postgresql://<user>:<password>@127.0.0.1:5432/<db_name>?serverVersion=18&charset=utf8"
AURORA_MOUNT_POINT_KEY=<même valeur que dans .env.local>
AURORA_ENCRYPTION_KEY=<même valeur que dans .env.local>
```

Garde le même `<db_name>` que pour le dev : Doctrine ajoute un suffixe
`_test` en environnement de test, la base de test est donc
`<db_name>_test`. `make db-test` la crée (et `make ft` la recrée à
chaque passage, tu n'as rien à lancer de plus).

---

## 4. `make install-dev`

```bash
make install-dev
```

Dans l'ordre :

1. `composer install` du projet, d'aurora-core
   (`vendor/axelraboit/aurora/`) et de ses quatre linters (PHP CS Fixer,
   Twig CS Fixer, Rector, PHPStan)
2. `pnpm install` dans aurora-core et dans le projet, puis
   `make setup-dirs`
3. **Supprime et recrée la base** (`doctrine:database:drop` +
   `doctrine:database:create`)
4. `doctrine:schema:create` depuis les entités, puis marque toutes les
   migrations comme appliquées. Pas `make migrate` : sur une base neuve,
   les migrations du client et celles d'aurora-core ne s'entrelacent pas
   dans le bon ordre (détails dans [`../dev/database.md`](../dev/database.md),
   section "DB fresh : `make migrate` ne marche pas")
5. `messenger:setup-transports` (crée la table `messenger_messages`)
6. `aurora:install` (données de socle : locales, thème, types de
   publication, menus)
7. Charge les fixtures (`doctrine:fixtures:load --append`)
8. `aurora:application-parameter` + `aurora:privileges:sync`
9. Affiche le compte de connexion, puis lance **Vite au premier plan**
   (`make dev`)

La dernière étape ne rend jamais la main : c'est normal, laisse ce
terminal ouvert, Vite sert les assets sur `localhost:5173`.

---

## 5. Lancer l'app

Dans un **deuxième terminal** :

```bash
make start-d
```

Le serveur Symfony démarre en arrière-plan. L'app répond sur
`https://127.0.0.1:8000` (ou le port affiché).

Dans un **troisième terminal**, le worker Messenger :

```bash
make start-dev-worker
```

Sans lui, tout ce qui passe en tâche de fond attend : publications
programmées et tâches du planificateur, mails des formulaires,
déplacement des documents dans la médiathèque, récapitulatif de
l'espace client.

Connexion :

| Champ | Valeur |
|---|---|
| Email | `dev@aurora.app` (le compte que les fixtures sèment toujours) |
| Mot de passe | `password` (fixtures de dev uniquement) |

Les jours suivants, `make start` suffit : il lance le serveur Symfony
en arrière-plan puis Vite au premier plan, dans le même terminal.

---

## 6. Vérifier que tout marche

```bash
make ft
```

`make fix` (linters), puis les tests PHP et JS, puis `make
migrate-check`. Tout doit être vert. Si les tests plantent côté base,
relis `.env.test.local` (§3).

---

## 7. Et après

Pour récupérer le travail de l'équipe :

```bash
git pull && make pull-update
```

> ⚠️ **Ne relance jamais `make install-dev` sur un projet déjà
> installé** : il supprime la base, tes données locales disparaissent.
> Il ne sert qu'une fois, sur un clone neuf.

---

## Le quotidien

| Situation | Commande | Effet |
|---|---|---|
| Récupérer la PR d'un collègue | `make pull-update` | Deps depuis le lock (dont les quatre linters), migrations, cache, `sync-env`, `sync-readme` et les autres syncs. La base est conservée |
| Monter volontairement aurora-core | `make aurora-update` | Monte aurora-core au dernier tag stable de la contrainte (`^1.0`), puis sous-installs, syncs, traductions et build |
| Lancer le dev | `make start` | Serveur Symfony en arrière-plan + Vite au premier plan |
| Serveur seul | `make start-d` | Serveur Symfony en arrière-plan |
| Vite seul (serveur déjà lancé) | `make dev` | Serveur Vite uniquement |
| Tâches de fond | `make start-dev-worker` | Worker Messenger (async + planificateur) |
| Tests + linters | `make ft` | `make fix` + `make test` + `make migrate-check` |
| Données de démo | `make demo` | Fixtures du groupe `demo` ajoutées (`--append`) + syncs |

Détail des cibles : [`../dev/dev_workflow.md`](../dev/dev_workflow.md) ;
mises à jour : [`../dev/update_aurora.md`](../dev/update_aurora.md).

### Fixtures

| Pour... | Utiliser |
|---|---|
| Ajouter les fixtures **par-dessus** les données existantes (`--append`), dev uniquement | `make fixtures-load` |
| La même chose, sans le garde-fou dev | `make fixtures-append` |
| Reset complet (suppression de la base + schéma + fixtures + syncs) | `make fixtures` |
| Reset complet **et** réinstallation des deps + Vite | `make install-dev` |

---

## Ce que les synchronisations n'écrasent jamais

`make pull-update` et `make aurora-update` resynchronisent plusieurs
fichiers depuis aurora-core (`Makefile`, `security.yaml`, le bloc
canonique du `README.md`, les liens `CLAUDE.md` et `.claude/memory/`).
Trois endroits restent à toi :

- **Le `README.md`, hors du bloc canonique** : tout ce qui est au-dessus
  de `<!-- aurora-canonical:start -->` ou en dessous de
  `<!-- aurora-canonical:end -->` (le titre, l'intro, la section
  "Spécifique à ce projet"). `make sync-readme` ne remplace que ce qu'il
  y a entre les deux.
- **`CLAUDE.local.md`** : instructions Claude propres au projet
  (conventions internes, intégrations tierces).
- **`Makefile.local`** : cibles Makefile propres au projet (déploiement,
  intégrations CI/CD). Le `Makefile` l'inclut, ses cibles s'appellent
  avec `make <cible>` comme les autres.

Ces deux fichiers sont optionnels : ils n'existent pas tant que tu ne les
crées pas, et ils ne sont pas gitignorés par défaut. C'est à l'équipe de
décider : les committer quand la conf est partagée, ou les ajouter au
`.gitignore` quand elle est personnelle.

Le tableau complet de ce qui est écrasé ou préservé est dans
[`../dev/update_aurora.md`](../dev/update_aurora.md#customisations-préservées-au-sync).

---

## Troubleshooting

### Page sans CSS ni JS

Vérifie le lien `public/build` :

```bash
ls -la public/build
# doit pointer vers : public/build -> ../vendor/axelraboit/aurora/public/build
```

S'il manque : `ln -s ../vendor/axelraboit/aurora/public/build public/build`,
puis `make build`. Contexte dans [`../dev/assets_vue.md`](../dev/assets_vue.md),
section "Symlink `public/build` → vendor".

### `AURORA_ENCRYPTION_KEY must be a base64-encoded 32-byte key`

`.env.local` ou `.env.test.local` n'a pas cette variable (ou garde la
valeur d'exemple). Cf. §2 et §3.

### `FATAL: password authentication failed for user "app"`

L'environnement lit encore les identifiants d'exemple de `.env`
(`app` / `!ChangeMe!`). Le plus souvent : `.env.test.local` manque ou
n'a pas de `DATABASE_URL`. Mets tes vrais identifiants.

### `permission denied to create database`

Le rôle PostgreSQL de `DATABASE_URL` n'a pas le droit `CREATEDB`.
Donne-le lui (`ALTER ROLE <user> CREATEDB;` en superutilisateur) ou
utilise un autre rôle.

### `relation "core_<table>" does not exist` pendant les migrations

Tu as lancé `make migrate` sur une base neuve : il bute sur l'ordre des
migrations entre namespaces. Sur une base neuve, passe par
`make install-dev` (premier clone) ou `make fixtures` (reset). `make
migrate` ne sert qu'en incrémental, et `make pull-update` l'inclut déjà.

### `make ft` plante sur `make db-test`

`.env.test.local` mal configuré (identifiants, ou rôle sans `CREATEDB`).
Cf. §3.

### Les publications programmées ne partent pas, les mails de formulaire n'arrivent pas

Le worker ne tourne pas : `make start-dev-worker` (§5).
