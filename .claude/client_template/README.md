# <Project name - to rename>

> 📦 **Template d'amorçage**. Ce fichier vit dans aurora-core à
> `vendor/axelraboit/aurora/.claude/client_template/README.md`. À la
> première installation, `make aurora-update` (ou `make sync-readme`)
> le copie à la racine du projet client comme `README.md`. Renomme le
> titre + adapte l'intro au-dessus du marker `aurora-canonical:start`,
> et complète la section "Spécifique à ce projet" en bas du fichier.

A client application built on [Aurora](https://github.com/AxelRaboit/aurora-core).
Aurora is installed as a Composer dependency at `vendor/axelraboit/aurora/`
and provides the full suite (CRUD, auth, modules, Vue admin SPA, etc.).

<!-- aurora-canonical:start - managed by `make sync-readme`. Don't edit between markers; changes will be overwritten. -->

## Ce que fait ce projet

Un site public et son back-office, construits sur Aurora : publications et
pages composées en grille, médiathèque, formulaires, et selon les modules
activés, le Studio (clients, contrats signés en ligne, espaces clients), les
notes et les calendriers. Le tout en français, anglais et espagnol.

Aurora fournit le socle ; ce dépôt ne contient que ce qui est propre au
projet : ses modules, ses surcharges d'écrans et de gabarits, son thème, sa
configuration. Ce qui le distingue est décrit plus bas, dans « Spécifique à ce
projet ».

## Documentation

- **Installer le projet, le rejoindre, les commandes du quotidien** :
  [joining_a_project.md](https://github.com/AxelRaboit/aurora-core/blob/develop/docs/aurora-client/getting-started/joining_a_project.md)
- **Versions et prérequis** :
  [prerequisites.md](https://github.com/AxelRaboit/aurora-core/blob/develop/docs/aurora-core/ops/prerequisites.md)
- **Tout le reste** (architecture, étendre un module, mettre Aurora à jour,
  CI, déploiement) est livré avec Aurora, à la version installée : une fois
  les dépendances en place, l'index est `docs/aurora-client/README.md` dans
  `vendor/axelraboit/aurora/`.

Les deux premiers liens visent GitHub parce qu'on les lit avant
`composer install`, quand `vendor/` n'existe pas encore.

Pour Claude Code, `CLAUDE.md` indexe cette documentation et se charge à
chaque session (c'est un lien vers `vendor/`, présent après l'installation).

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
