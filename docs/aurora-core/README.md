# Documentation Aurora Core

> Conventions trans-couches (validation, tests, i18n, SEO, scheduler) :
> [`../aurora-shared/`](../aurora-shared/README.md).

## Philosophie

| Fichier | Contenu |
|---|---|
| [philosophy.md](philosophy.md) | Principes fondateurs, pattern d'extensibilité, système de modules |

## todo/ - Tâches techniques en attente

| Fichier | Contenu |
|---|---|
| [todo/](todo/README.md) | Index des TODOs techniques, organisés par module puis par topic (catalogue, tarification, livraison…) |
| [module_roadmap.md](todo/module_roadmap.md) | Modules à venir, classés par priorité |

## dev/ - Guides développeur (internes au bundle)

| Fichier | Contenu |
|---|---|
| [app_architecture.md](dev/app_architecture.md) | Vue d'ensemble de l'architecture (Symfony, Vue 3, modules) |
| [entity_extensibility_convention.md](dev/entity_extensibility_convention.md) | Convention d'extensibilité des entités (5 couches, pattern Sylius) |
| [extending_aurora.md](dev/extending_aurora.md) | Comment utiliser Aurora Core comme base d'une app client |
| [extending_agency_pilot.md](dev/extending_agency_pilot.md) | Guide pas-à-pas d'extension complète (exemple Agency) |
| [add_module.md](dev/add_module.md) | Ajouter un module à aurora-core (checklist) |
| [css_conventions.md](dev/css_conventions.md) | Organisation de `src/Core/assets/css/` (orchestration des imports) |
| [frontend_theme_override.md](dev/frontend_theme_override.md) | `ThemeResolver` - système de fallback des templates frontend |
| [per_user_module_access.md](dev/per_user_module_access.md) | Toggle modules par utilisateur (`UserModuleAccess`) |
| [single_locale_mode.md](dev/single_locale_mode.md) | Mode mono-langue - bascule chaude, effets sur routes/forms/fixtures |
| [propagating_updates.md](dev/propagating_updates.md) | Propager une mise à jour d'aurora-core aux projets consommateurs (aurora-client = modèle / canari) - procédure `make aurora-update`, releases plus tard |

## ops/ - Prérequis bundle

| Fichier | Contenu |
|---|---|
| [prerequisites.md](ops/prerequisites.md) | Checklist exhaustive des dépendances système, PHP, Node, vars d'env |

> Tout ce qui touche au **déploiement** d'un projet client (séquence install-prod, systemd, mod_xsendfile, setup OCR) vit côté aurora-client : [`docs/aurora-client/deployment/`](../aurora-client/README.md#-déploiement-production).

