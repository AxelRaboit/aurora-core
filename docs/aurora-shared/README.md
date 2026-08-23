# Aurora-shared - Documentation transversale

Documents qui s'appliquent **aussi bien** à un développeur travaillant
dans aurora-core qu'à un développeur travaillant dans un projet
aurora-client : conventions de validation, patterns de tests, i18n,
SEO, scheduler.

Distribués via composer aux clients : `vendor/axelraboit/aurora/docs/aurora-shared/`,
exposés côté client à `docs/aurora-shared/` via le même mécanisme de
symlink que `aurora-core/` et `aurora-client/`.

> 📐 **Règle d'or** : si une convention s'applique uniquement à un
> module aurora-core (architecture interne, mappings Doctrine, pattern
> Sylius 5 couches), elle reste dans [`../aurora-core/dev/`](../aurora-core/README.md).
> Si elle s'applique uniquement à un projet client (override Twig,
> structure custom), elle reste dans [`../aurora-client/`](../aurora-client/README.md).

---

## Conventions trans-couches

| Fichier | Contenu |
|---|---|
| [form_validation.md](form_validation.md) | DTO + `PayloadValidator` côté PHP + `useForm` côté Vue - le contrat entre les trois |
| [convention_seo_head.md](convention_seo_head.md) | Macros / blocs Twig pour `<head>` SEO (Open Graph, canonical, JSON-LD) |
| [translations.md](translations.md) | Workflow i18n : extraction, scopes, override client |

## Tests

| Fichier | Contenu |
|---|---|
| [testing_php.md](testing_php.md) | Patterns PHPUnit : fixtures, helpers, mocks, kernel tests |
| [testing_vue.md](testing_vue.md) | Patterns Vitest : composables, components, `@vue/test-utils` |

## Tâches récurrentes

| Fichier | Contenu |
|---|---|
| [scheduler.md](scheduler.md) | Symfony Scheduler - ajouter des tâches périodiques, conventions de nommage, worker systemd |

---

## Mémoires associées

Voir aussi `.claude/memory/aurora-shared/` pour les **règles courtes**
qui complètent ces docs (préférences commit, no_cross_module_dep,
form_components, mobile_card_layout, etc.).
