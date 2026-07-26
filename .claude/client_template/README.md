# <Project name — to rename>

> 📦 **Template d'amorçage**. Ce fichier vit dans aurora-core à
> `vendor/axelraboit/aurora/.claude/client_template/README.md`. À la
> première installation, `make aurora-update` (ou `make sync-readme`)
> le copie à la racine du projet client comme `README.md`. Renomme le
> titre + adapte l'intro au-dessus du marker `aurora-canonical:start`,
> et complète la section "Spécifique à ce projet" en bas du fichier.

A client application built on [Aurora](https://github.com/AxelRaboit/aurora-core).
Aurora is installed as a Composer dependency at `vendor/axelraboit/aurora/`
and provides the full backend (CRUD, auth, modules, Vue admin SPA, etc.).

<!-- aurora-canonical:start — managed by `make sync-readme`. Don't edit between markers; changes will be overwritten. -->

## 🚀 Tu rejoins le projet ? Quickstart 10 min

Pour faire tourner ce projet en local après `git clone` :
**[`vendor/axelraboit/aurora/docs/aurora-client/getting-started/joining_a_project.md`](vendor/axelraboit/aurora/docs/aurora-client/getting-started/joining_a_project.md)**

TL;DR : `cp .env.local.example .env.local` + édite-le, puis
`make install-dev` (drop + reset + fixtures + Vite). Le quickstart
détaille les prérequis (PHP/Node/Postgres) et la procédure complète.

> 📋 **Checklist complète** des prérequis (PHP, Node, Postgres, binaires CLI, vars d'env, prod) : [`vendor/axelraboit/aurora/docs/aurora-core/ops/prerequisites.md`](vendor/axelraboit/aurora/docs/aurora-core/ops/prerequisites.md) — à lire avant l'install initial.
>
> ℹ️ Toute la doc Aurora vit dans `vendor/axelraboit/aurora/docs/` (trois dossiers : `aurora-core/`, `aurora-client/`, `aurora-shared/`). Pas de copie locale, pas de sync : un `composer update axelraboit/aurora` met le code et la doc à jour ensemble.

## Le quotidien

| Situation | Commande | Effet |
|---|---|---|
| Pull la PR d'un collègue | `make pull-update` | Sync deps + migrations + cache (préserve la BDD) |
| Bump volontaire d'aurora-core | `make aurora-update` | `composer update axelraboit/aurora` + sub-installs + syncs |
| Lancer le dev en local | `make start` ou `make dev` | PHP server + Vite watcher |
| Tests + linters | `make ft` | `make fix` + `make test` |
| Recharger les fixtures démo | `make demo` | `doctrine:fixtures:load --group=demo` |

⚠️ **Ne JAMAIS faire `make install-dev` sur un projet déjà setup** — il purge
la BDD via `doctrine:fixtures:load`. Les données locales sont écrasées.

| Pour... | Utiliser |
|---|---|
| Reload les fixtures **sans** drop la DB (tables purgées + réinsérées) | `make fixtures-load` |
| Ajouter les fixtures **sans** purger les tables existantes | `make fixtures-append` |
| Reset complet (drop DB + schema + fixtures + syncs) | `make fixtures` |
| Reset complet **et** reinstall deps + lancer Vite | `make install-dev` |

## Intégration continue (GitHub Actions)

Le template embarque `.github/workflows/ci.yml` qui run lint + build +
tests à chaque push/PR. Aucune config supplémentaire requise — aurora-core
est public, donc `composer install` se débrouille tout seul en CI.

Détails du workflow (matrix, customisation, init DB de test) :
[`vendor/axelraboit/aurora/docs/aurora-client/deployment/github_actions_ci.md`](vendor/axelraboit/aurora/docs/aurora-client/deployment/github_actions_ci.md)

## Comment utiliser Aurora ?

Toutes les conventions client-side (où mettre le code, comment override
les services / templates / composants Vue Aurora, comment étendre une
entité, …) vivent dans la doc d'Aurora pour rester en phase avec la
version installée :

| Sujet | Chemin |
|---|---|
| **Quickstart** — *où mettre mon code ?* | `vendor/axelraboit/aurora/docs/aurora-client/getting_started/setup.md` |
| Architecture du projet | `vendor/axelraboit/aurora/docs/aurora-client/getting_started/architecture.md` |
| Créer un nouveau module client | `vendor/axelraboit/aurora/docs/aurora-client/extending/add_module.md` |
| Étendre un module Aurora (5 couches, Twig, finders, décorateurs, permissions) | `vendor/axelraboit/aurora/docs/aurora-client/extending/extend_module.md` |
| Workflow dev quotidien | `vendor/axelraboit/aurora/docs/aurora-client/dev/dev_workflow.md` |
| Mises à jour Aurora (détaillé) | `vendor/axelraboit/aurora/docs/aurora-client/dev/update_aurora.md` |
| Conventions globales (Vue, fetch, JS, i18n, commits) | `vendor/axelraboit/aurora/.claude/memory/aurora-shared/MEMORY.md` |

Pour les utilisateurs de Claude Code, [`CLAUDE.md`](CLAUDE.md) (lui aussi
symlinké) indexe tout ça et est chargé automatiquement au démarrage de
chaque session.

## Customisation projet

Trois fichiers permettent d'ajouter du contenu spécifique au projet client
qui ne sera **jamais écrasé** par `make aurora-update` / `make sync-readme` :

- **Tout ce qui est au-dessus de `<!-- aurora-canonical:start -->` ou en
  dessous de `<!-- aurora-canonical:end -->`** dans **ce README** — le
  titre, l'intro, la section "Spécifique à ce projet"
- **`CLAUDE.local.md`** — instructions Claude spécifiques au projet
  (conventions internes, intégrations tierces)
- **`Makefile.local`** — targets Makefile custom (déploiement, intégrations
  CI/CD spécifiques)

Ces fichiers sont gitignorés/listés dans `.gitignore` côté client — vérifier
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
