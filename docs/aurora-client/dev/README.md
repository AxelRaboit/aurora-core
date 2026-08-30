# Documentation développeur - aurora-client

Documentation à destination d'un développeur qui :
- démarre un nouveau projet client sur aurora-core,
- maintient un projet existant et veut étendre / customiser le comportement
  d'aurora-core sans forker le bundle.

> Le bundle aurora-core est consommé via composer (`axelraboit/aurora`). Toutes
> les conventions ci-dessous concernent **votre repo client**, pas le bundle.

---

## 🚀 Par où commencer

Selon ce que vous faites, lisez dans cet ordre :

### Nouveau dev sur le projet
1. [Getting started](getting-started.md) - prérequis, premier `make setup`, structure obligatoire, premier déploiement local.
2. [Workflow de développement](dev_workflow.md) - commandes du quotidien, debug, ajout d'une feature.
3. [Assets Vue](assets_vue.md) - structure des assets côté client, aliases Vite, locales Vue.
4. [Mise à jour aurora-core](update_aurora.md) - `make aurora-update`, breaking changes.

### J'étends une entité / un Manager / une Vue d'aurora-core
1. [Étendre un module Aurora](../extending/extend_module.md) - recettes concrètes pour chaque couche (entité, DTO, Manager, Serializer, Vue, Twig) + permissions + décorer un service.
2. [Pattern `extraFields`](extra_fields_pattern.md) - ajouter des champs à un formulaire admin via slots Vue.
3. [Doc canonique de la convention 5 couches](../../aurora-core/dev/entity_extensibility_convention.md) (côté core).
4. [Tutoriel pas-à-pas Agency](../../aurora-core/dev/extending_agency_pilot.md) (côté core).

### J'ai besoin de l'API d'un composant Vue ou d'un composable
- [Catalogue des composants Vue partagés](shared_components_catalog.md) - `AppMultiselect`, `AppTab`, `AppBadge`, `AppModal`, etc.
- [Catalogue des composables et utils](composables_catalog.md) - `useFormAction`, `useDelete`, `useListPage`, `useHierarchicalTree`, `slugify`, etc.

### Je travaille sur la persistance
- [Base de données](database.md) - migrations, séquences, fixtures, ApplicationParameters.

### Je dois tester / déployer
- [Tests côté client](testing_client.md) - comment tester un override, mocking, pitfalls.
- [Déploiement](deployment.md) - séquence prod, env vars critiques, post-deploy.

### Je configure l'outillage IA (Claude Code etc.)
- [Mémoire IA](memory_for_ai.md) - structure `.claude/memory/aurora-client/`, hygiène, sync.

---

## 📑 Index complet

| Doc | Sujet | Quand la lire |
|---|---|---|
| [getting-started.md](getting-started.md) | Onboarding zéro | Premier jour sur le projet |
| [dev_workflow.md](dev_workflow.md) | Commandes quotidiennes, debug | Tous les jours |
| [../extending/extend_module.md](../extending/extend_module.md) | Recettes d'extension par couche (entité, Twig, finders, décorateurs, permissions) | À chaque override |
| [extra_fields_pattern.md](extra_fields_pattern.md) | `extraFields` + slots Vue | Ajout de champs à un form admin |
| [shared_components_catalog.md](shared_components_catalog.md) | API des `App*.vue` partagés | Référence Ctrl+F |
| [composables_catalog.md](composables_catalog.md) | API des composables et utils | Référence Ctrl+F |
| [assets_vue.md](assets_vue.md) | Structure assets, aliases, locales | Setup Vue côté client |
| [database.md](database.md) | Migrations, séquences, fixtures | Modif schema |
| [testing_client.md](testing_client.md) | Setup tests + pitfalls | Avant d'écrire un test |
| [deployment.md](deployment.md) | Séquence prod, env vars | Avant de déployer |
| [update_aurora.md](update_aurora.md) | `make aurora-update` | Mise à jour mensuelle |
| [memory_for_ai.md](memory_for_ai.md) | `.claude/memory/aurora-client/` | Setup outillage IA |

---

## 📚 Docs canoniques côté aurora-core

Certaines règles sont définies dans `docs/aurora-core/dev/` parce qu'elles
appartiennent au bundle. Elles vous concernent aussi en tant que dev client.
Ouvrez-les depuis ce repo via `vendor/axelraboit/aurora/docs/aurora-core/dev/`
ou consultez la version en ligne :

| Doc core | Pourquoi vous en avez besoin |
|---|---|
| [`entity_extensibility_convention.md`](../../aurora-core/dev/entity_extensibility_convention.md) | **Doc canonique** de la convention 5 couches. À lire au moins une fois. |
| [`extend_module.md`](../extending/extend_module.md) | Cheatsheet d'extension côté client (5 couches + Twig + finders + décorateurs + permissions). |
| [`extending_aurora.md`](../../aurora-core/dev/extending_aurora.md) | Vue d'ensemble du modèle d'extensibilité. |
| [`extending_agency_pilot.md`](../../aurora-core/dev/extending_agency_pilot.md) | Tutorial complet : étendre `Agency` avec `code`. |
| [`add_module.md`](../../aurora-core/dev/add_module.md) | Créer un nouveau module dans votre repo client. |
| [`app_architecture.md`](../../aurora-core/dev/app_architecture.md) | Cartographie d'aurora-core (modules, namespaces). |
| [`frontend_theme_override.md`](../../aurora-core/dev/frontend_theme_override.md) | Créer son thème frontend. |
| [`form_validation.md`](../../aurora-shared/form_validation.md) | Conventions de validation de form. |
| [`per_user_module_access.md`](../../aurora-core/dev/per_user_module_access.md) | RBAC + désactivation de modules par utilisateur. |
| [`translations.md`](../../aurora-shared/translations.md) | i18n côté core et client. |
| [`testing_php.md`](../../aurora-shared/testing_php.md) | Fondamentaux PHPUnit (s'applique aussi côté client). |
| [`testing_vue.md`](../../aurora-shared/testing_vue.md) | Fondamentaux Vitest. |
| [`scheduler.md`](../../aurora-shared/scheduler.md) | Cron / scheduler d'aurora-core. |

---

## 🧠 Conventions principales à connaître

Trois règles dures qui structurent tout le reste :

1. **Convention 5 couches** : chaque entité avec CRUD admin = Interface + Abstract +
   concrete non-final, DTO + InputFactory `#[AsAlias]`, Manager avec hooks
   `protected`, Serializer non-final, Vue avec `extraFields` + slots. Voir
   [`entity_extensibility_convention.md`](../../aurora-core/dev/entity_extensibility_convention.md).

2. **Type-hint d'interface partout** dans les signatures publiques (controllers,
   managers, serializers, getters/setters de relations). Permet la substitution
   `#[AsAlias]` côté client. Voir [extend_module.md](../extending/extend_module.md).

3. **Templates Twig = passerelles Vue**. Côté frontend public ET côté admin,
   `{% block body %}` ne contient qu'un `{{ vue_component(...) }}`. Le head meta
   Twig reste pour SEO. Voir
   [`convention_frontend_rendering`](../../../.claude/memory/aurora-core/vue-frontend/convention_frontend_rendering.md).

---

## 🤝 Contribuer à cette doc

Si vous tombez sur un sujet absent ou périmé pendant que vous codez :
- Pour les conventions transverses → ajoutez une mémoire dans
  `.claude/memory/aurora-client/` (cf [memory_for_ai.md](memory_for_ai.md)) et,
  si la convention est durable, pondez aussi un fichier ici.
- Pour les recettes d'override / patterns → enrichissez
  [../extending/extend_module.md](../extending/extend_module.md) ou [extra_fields_pattern.md](extra_fields_pattern.md).
- Pour l'API d'un composant Vue qui change → mettez à jour
  [shared_components_catalog.md](shared_components_catalog.md) ou
  [composables_catalog.md](composables_catalog.md).
