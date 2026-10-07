<div align="center">

<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/images/aurora-logo.svg">
    <img src="docs/images/aurora-logo-fond-clair.svg" alt="" height="48" align="absmiddle">
  </picture>
  Aurora
</h1>

**Un site, son back-office et l'espace de travail d'une petite agence, dans un seul bundle Symfony**

[![Symfony](https://img.shields.io/badge/Symfony-7.4-000000?style=flat-square&logo=symfony&logoColor=white)](https://symfony.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?style=flat-square&logo=vue.js&logoColor=white)](https://vuejs.org)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind-4-38BDF8?style=flat-square&logo=tailwind-css&logoColor=white)](https://tailwindcss.com)
[![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Vite](https://img.shields.io/badge/Vite-8-646CFF?style=flat-square&logo=vite&logoColor=white)](https://vitejs.dev)

</div>

---

## Ce que fait Aurora

Aurora sert à faire tourner un site public et tout ce qu'il faut pour le
nourrir : les contenus, les médias, et le travail avec les clients. C'est un
bundle Symfony ; un site se construit dans un projet client
([aurora-client](https://github.com/AxelRaboit/aurora-client)) qui l'installe
par Composer et y ajoute ce qui lui est propre.

Les pages, du site comme du back-office, sont rendues par Symfony en Twig, et
leurs parties interactives sont des composants Vue 3 montés dedans
(`symfony/ux-vue`) : tout le back-office, et côté site les formulaires, les
galeries, les grilles de zones, la connexion et le compte. Tout se lit en
trois langues : français, anglais, espagnol.

### Les modules

| Module | Ce qu'il fait |
|---|---|
| **Éditorial** | Publications et pages composées en grille de zones (texte Editor.js, images, galeries, cartes, carrousels, formulaires, sondages, réservations…), types de contenu et taxonomies, menus, commentaires, formulaires et leurs réponses, lettre d'information, référencement, recherche, révisions et relecture avant publication, programmation |
| **Médiathèque** | Documents rangés en dossiers, catégories et étiquettes, variantes d'une même image, aperçus des PDF et des vidéos, stockage sur le disque ou dans Cloudflare R2, import depuis Pexels |
| **Studio** | Clients, contrats avec signature électronique et scellé vérifiable, présentations, calendrier éditorial, les livrables (audits, stratégies, propositions, perso ou partagés avec l'équipe, ou rangés dans l'espace d'un client, avec liens de lecture et mode présentation), et l'espace de chaque client : contenus à faire relire, calendrier, discussion en direct, fichiers, Google Drive, livrables, notes, ressources et accès du client |
| **Notes** | Notes Markdown en dossiers et en espaces partagés, liens entre notes et leur graphe, partage par lien |
| **Planning** | Calendriers, évènements récurrents, rappels, invités, flux iCal et partage par lien |
| **Plateforme** | Utilisateurs, rôles et privilèges, invitations, demandes d'accès |
| **Configuration** | Réglages du site, thèmes du site public, intégrations (Google Drive, Instagram, avis Google, GitHub, anti-robots, lettre d'information, Pexels, Craft) |
| **Général et Dev** | Tableau de bord, profil, corbeille commune, recherche globale ; administration, journal d'audit et activation des modules |

Chaque module s'active ou se coupe depuis l'administration, et un projet client
peut étendre ses entités, ses écrans et ses gabarits sans le forker.

---

## Stack technique

| Couche | Technologie |
|--------|-------------|
| Backend | Symfony 7.4, PHP 8.4, Doctrine ORM 3 |
| Base de données | PostgreSQL |
| Interface | Pages Twig, composants Vue 3 montés par `symfony/ux-vue`, vue-i18n |
| Édition | Editor.js, grille de zones maison |
| Style | Tailwind CSS 4, thèmes du site réglables depuis le back-office |
| Tâches de fond | Symfony Messenger et Scheduler (transport Doctrine, sans broker) |
| Temps réel | Mercure (discussions des espaces clients) |
| Build | Vite 8 |
| Tests | PHPUnit, Vitest, Playwright |

---

## Documentation

Tout ce qui s'installe, se lance ou se déploie vit dans [`docs/`](docs/README.md) :

- **Installer aurora-core et travailler dessus** : [`docs/aurora-core/dev/getting_started.md`](docs/aurora-core/dev/getting_started.md)
- **Créer ou rejoindre un projet client** : [`docs/aurora-client/getting-started/joining_a_project.md`](docs/aurora-client/getting-started/joining_a_project.md)
- **Versions et prérequis** : [`docs/aurora-core/ops/prerequisites.md`](docs/aurora-core/ops/prerequisites.md)
- **Architecture et conventions** : [`docs/aurora-core/`](docs/aurora-core/README.md), [`docs/aurora-shared/`](docs/aurora-shared/README.md)

---

## Licence

Propriétaire, tous droits réservés : voir [`LICENSE`](LICENSE).
