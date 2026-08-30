# <Project name - to rename>

> 📦 **Template d'amorçage**. Ce fichier vit dans aurora-core à
> `vendor/axelraboit/aurora/.claude/client_template/README.md`. À la
> première installation, `make aurora-update` (ou `make sync-readme`)
> le copie à la racine du projet client comme `README.md`. Renomme le
> titre + adapte l'intro au-dessus du marker `aurora-canonical:start`,
> et complète la section "Spécifique à ce projet" en bas du fichier.

A client application built on [Aurora](https://github.com/AxelRaboit/aurora-core).
Aurora is installed as a Composer dependency at `vendor/axelraboit/aurora/`
and provides the full backend (CRUD, auth, modules, Vue admin SPA, etc.).

<!-- aurora-canonical:start - managed by `make sync-readme`. Don't edit between markers; changes will be overwritten. -->

## 🚀 Tu rejoins le projet ? Quickstart 10 min

**Trois commandes, dans cet ordre :**

```bash
cp .env.local.example .env.local   # puis édite-le (DATABASE_URL, APP_SECRET)
make install-dev                   # installe les deps, crée la BDD, charge les fixtures
make start                         # serveur PHP + Vite
```

`make install-dev` lance lui-même les `composer install` : tu n'as rien à
installer à la main avant. C'est aussi lui qui crée `vendor/`.

**Le guide complet** (prérequis, Postgres en local ou via Docker, dépannage) :
[joining_a_project.md](https://github.com/AxelRaboit/aurora-core/blob/develop/docs/aurora-client/getting-started/joining_a_project.md)

> 📋 **Checklist des prérequis** (PHP 8.4, Node 24, pnpm 10, Postgres 18,
> Symfony CLI, vars d'env, prod) :
> [prerequisites.md](https://github.com/AxelRaboit/aurora-core/blob/develop/docs/aurora-core/ops/prerequisites.md) - à lire
> avant la première install.

> ℹ️ **Pourquoi ces deux liens pointent vers GitHub et pas vers `vendor/`.**
> Toute la doc Aurora est livrée avec le paquet, dans
> `vendor/axelraboit/aurora/docs/`. Mais `vendor/` n'existe pas tant que
> `composer install` n'a pas tourné : au moment où tu lis ce quickstart,
> juste après un `git clone`, ces fichiers ne sont pas encore sur ton
> disque. Les deux docs qu'on lit *avant* d'installer pointent donc vers
> GitHub (le dépôt est public) ; tout le reste de ce README pointe vers
> `vendor/`, ce qui a l'avantage de te montrer la doc **de la version que
> tu as installée** plutôt que celle de `develop`.

## Le quotidien

| Situation | Commande | Effet |
|---|---|---|
| Pull la PR d'un collègue | `make pull-update` | Deps depuis le lock + migrations + cache + syncs (préserve la BDD) |
| Bump volontaire d'aurora-core | `make aurora-update` | `composer update axelraboit/aurora` + sub-installs + syncs |
| Lancer le dev en local | `make start` | Docker (base) + serveur Symfony + Vite |
| Vite seul (serveur déjà lancé) | `make dev` | Vite dev server uniquement |
| Tests + linters | `make ft` | `make fix` + `make test` + `make migrate-check` |
| Recharger les fixtures démo | `make demo` | `doctrine:fixtures:load --group=demo --append` |

⚠️ **Ne JAMAIS faire `make install-dev` sur un projet déjà setup** - il purge
la BDD via `doctrine:fixtures:load`. Les données locales sont écrasées.

| Pour... | Utiliser |
|---|---|
| Reload les fixtures **sans** drop la DB (tables purgées + réinsérées) | `make fixtures-load` |
| Ajouter les fixtures **sans** purger les tables existantes | `make fixtures-append` |
| Reset complet (drop DB + schema + fixtures + syncs) | `make fixtures` |
| Reset complet **et** reinstall deps + lancer Vite | `make install-dev` |

## Intégration continue (GitHub Actions)

Le template embarque `.github/workflows/ci.yml` qui run lint + build +
tests à chaque push/PR. Aucune config supplémentaire requise - aurora-core
est public, donc `composer install` se débrouille tout seul en CI.

Détails du workflow (matrix, customisation, init DB de test) :
`docs/aurora-client/deployment/github_actions_ci.md` (dans `vendor/axelraboit/aurora/`)

## Comment utiliser Aurora ?

Toutes les conventions client-side (où mettre le code, comment override
les services / templates / composants Vue Aurora, comment étendre une
entité, …) vivent dans la doc d'Aurora pour rester en phase avec la
version installée. Chemins relatifs à `vendor/axelraboit/aurora/` :

| Sujet | Chemin |
|---|---|
| **Quickstart** - *où mettre mon code ?* | `docs/aurora-client/getting-started/setup.md` |
| Architecture du projet | `docs/aurora-client/getting-started/architecture.md` |
| Philosophie d'Aurora | `docs/aurora-client/getting-started/philosophy.md` |
| Créer un nouveau module client | `docs/aurora-client/extending/add_module.md` |
| Étendre un module Aurora (5 couches, Twig, finders, décorateurs, permissions) | `docs/aurora-client/extending/extend_module.md` |
| Workflow dev quotidien | `docs/aurora-client/dev/dev_workflow.md` |
| Mises à jour Aurora (détaillé) | `docs/aurora-client/dev/update_aurora.md` |
| Conventions globales (Vue, fetch, JS, i18n, commits) | `.claude/memory/aurora-shared/MEMORY.md` |

Pour les utilisateurs de Claude Code, [`CLAUDE.md`](CLAUDE.md) indexe tout
ça et est chargé automatiquement au démarrage de chaque session. C'est un
lien symbolique vers `vendor/`, donc lui aussi n'existe qu'une fois les
dépendances installées.

## Customisation projet

Trois endroits permettent d'ajouter du contenu spécifique au projet client
qui ne sera **jamais écrasé** par `make aurora-update` / `make sync-readme` :

- **Tout ce qui est au-dessus de `<!-- aurora-canonical:start -->` ou en
  dessous de `<!-- aurora-canonical:end -->`** dans **ce README** - le
  titre, l'intro, la section "Spécifique à ce projet"
- **`CLAUDE.local.md`** - instructions Claude spécifiques au projet
  (conventions internes, intégrations tierces)
- **`Makefile.local`** - targets Makefile custom (déploiement, intégrations
  CI/CD spécifiques)

Les deux derniers sont optionnels : ils n'existent pas tant que tu ne les
crées pas, et ils ne sont pas gitignorés par défaut. À toi de décider si
tu les commit (utile quand la conf est partagée par l'équipe) ou si tu les
ajoutes à ton `.gitignore` (utile quand elle est personnelle).

<!-- aurora-canonical:end -->`** dans **ce README** - le
  titre, l'intro, la section "Spécifique à ce projet"
- **`CLAUDE.local.md`** - instructions Claude spécifiques au projet
  (conventions internes, intégrations tierces)
- **`Makefile.local`** - targets Makefile custom (déploiement, intégrations
  CI/CD spécifiques)

Ces fichiers sont gitignorés/listés dans `.gitignore` côté client - vérifier
selon vos pratiques d'équipe si vous voulez les commit ou non.

<!-- aurora-canonical:end -->

## Spécifique à ce projet

<!--
  À remplir par le client. Exemples :
  - URL de staging / prod
  - Contacts équipe / chefs de projet
  - Choix d'archi spécifiques au projet (jamais redondants avec aurora-core)
  - Particularités métier qui ne tiennent dans aucun doc générique
-->

_TODO : compléter avec les informations propres au projet._
