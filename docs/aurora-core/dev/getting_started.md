# Installer aurora-core et travailler dessus

Cette page sert à faire tourner **le bundle lui-même**, avec son jeu de
démonstration : pour développer Aurora, lancer ses tests, prendre les captures
du tour. Pour construire un site, c'est un projet client qu'il faut :
[`../../aurora-client/getting-started/joining_a_project.md`](../../aurora-client/getting-started/joining_a_project.md).

Les versions exactes, les extensions et les binaires facultatifs sont tenus à
jour dans une seule page : [`../ops/prerequisites.md`](../ops/prerequisites.md).

## 1. Les outils

- PHP 8.4 avec `pdo_pgsql`, `intl`, `mbstring`, `sodium`, `gd`, `zip`, `xml`,
  `ctype`, `iconv` (`exif` facultatif)
- Composer 2
- Node 24 (Vite 8 demande au moins 20.19 ou 22.12) et pnpm 10
  (`make pnpm-setup VERSION=10.x.y` l'active par corepack)
- PostgreSQL 18 (14 et plus tolérés), lancé sur la machine : aucun conteneur
  ne le fournit
- [Symfony CLI](https://symfony.com/download), pour le serveur local
- Docker avec compose v2, pour Mailpit (les emails de dev) et Mercure (les
  discussions en direct). Sans Docker, tout marche : les emails ne s'affichent
  nulle part et les discussions se rafraîchissent toutes les 20 secondes.
- Facultatifs : `pdftoppm` (poppler) ou `gs` (Ghostscript) pour les aperçus de
  PDF, `ffmpeg` pour les vignettes des vidéos. Sans eux, la médiathèque montre
  une icône à la place.

## 2. Récupérer le code et l'environnement

```bash
git clone https://github.com/AxelRaboit/aurora-core.git
cd aurora-core
make setup-env
```

`make setup-env` copie `.env.local.example` en `.env.local` et génère
`APP_SECRET`. Dans `.env.local`, vérifier ou ajouter :

```dotenv
DATABASE_URL="postgresql://<utilisateur>:<mot_de_passe>@127.0.0.1:5432/aurora_dev?serverVersion=18&charset=utf8"
# 32 octets en base64 : php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
AURORA_ENCRYPTION_KEY=<clé générée>
AURORA_MOUNT_POINT_KEY=<autre clé générée>
```

Le reste a déjà une valeur de dev dans `.env` et `.env.dev` : `MAILER_DSN`
pointe sur Mailpit (`smtp://localhost:1025`), Mercure sur le port 3000,
Messenger sur la base (`doctrine://default`, aucun broker à installer).

Les tests lisent `.env.test`, pas `.env.local`. Si PostgreSQL n'a pas les
identifiants de `.env.test`, créer `.env.test.local` avec son propre
`DATABASE_URL` ; la base de test prend le suffixe `_test`.

## 3. Installer

```bash
make db-create
make install-dev
```

`make install-dev` installe les dépendances Composer (l'application et les
quatre outils de qualité), les dépendances pnpm, crée les dossiers de travail,
joue les migrations (répondre `yes`), synchronise les réglages, pose les
données obligatoires (`aurora:install`) et compile les traductions pour le
front. Il finit en lançant Vite **au premier plan** : la commande ne rend pas
la main, c'est normal.

Il ne crée pas la base : c'est le rôle de `make db-create`, juste avant.

## 4. La démonstration

```bash
make demo
```

Charge le jeu de démonstration par-dessus les données existantes, sans rien
effacer ; on peut le relancer. Le compte est `dev@aurora.app`, mot de passe
`password` (fixtures de dev uniquement).

`make demo-reset` reconstruit tout depuis zéro : base supprimée, fichiers
stockés effacés, réglages du back-office gardés.

## 5. Lancer

Trois terminaux :

```bash
make start              # serveur Symfony en arrière-plan, plus Mailpit et Mercure
make watch              # Vite, au premier plan
make start-dev-worker   # le worker Messenger, au premier plan
```

- Le site et le back-office : `https://127.0.0.1:8000` (`/suite`).
- Les emails envoyés : Mailpit sur `http://localhost:8025`.
- Le worker est nécessaire pour les publications programmées et toutes les
  tâches planifiées, les emails des formulaires, les déplacements de fichiers
  de la médiathèque et le récapitulatif des espaces clients. Il consomme
  `async` et `scheduler_main`.

`make stop` arrête le serveur et Mercure ; `make stop-dev-worker` libère la
base avant une suppression (`make fixtures`, `make demo-reset` le font
eux-mêmes).

## 6. Tester

```bash
make ft      # la porte avant de pousser : formatage, tests, build, mapping, migrations
make ftl     # la même sans le build des assets
```

Le détail, si besoin :

```bash
make test-frontend             # Vitest
make test-backend-unit         # PHPUnit, tests unitaires
make test-backend-integration  # PHPUnit, intégration (crée la base de test)
make test                      # front et back
make test-e2e                  # Playwright
```

Les tests d'intégration ont besoin d'assets compilés (`make build`) : `make ft`
s'en charge, `make ftl` non.

Pour Playwright, installer le navigateur une fois :

```bash
pnpm exec playwright install chromium
# Linux seulement : sudo apt install -y libnspr4 libnss3 libasound2t64
```

Il démarre lui-même un serveur Symfony sur `http://127.0.0.1:8000` ; pour viser
une instance déjà lancée : `E2E_BASE_URL=http://127.0.0.1:8000 pnpm test:e2e`.

## Commandes utiles

```bash
make help              # toutes les cibles, avec leur description
make fix               # composer validate, ESLint, Twig, Rector, PHP-CS-Fixer, PHPStan
make stan              # PHPStan seul
make migration         # générer une migration
make migrate           # jouer les migrations
make translation       # recompiler les traductions du front (après une clé ajoutée)
make fixtures          # base supprimée puis recréée, toutes les fixtures
```

Pour livrer une version, le processus (PR, CHANGELOG, tag posé par la CI) est
dans [`propagating_updates.md`](propagating_updates.md) ; le déploiement d'un
site se fait depuis son projet client,
[`../../aurora-client/deployment/`](../../aurora-client/deployment/README.md).
