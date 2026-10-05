# Convention : breadcrumb - premier fil = section de navigation

## Règle

Chaque `page_header` backend commence son breadcrumb par le label de section :

```twig
{label: 'suite.nav.sections.<moduleId>'|trans},
{label: 'suite.nav.xxx'|trans},              {# index : sans href #}
{label: 'suite.nav.xxx'|trans, href: path('...')},  {# sous-page : avec href vers la liste #}
{label: element.name},                         {# page détail : nom de l'entité #}
```

**Ne jamais utiliser `suite.modules.*` dans un breadcrumb** - ces clés dupliquent souvent le label de section (ex : `suite.modules.crm` = "CRM" = `suite.nav.sections.crm`). Utiliser directement le label de liste (`suite.nav.contacts`, etc.).

## Clés de section disponibles (moduleId → clé → valeur FR)

| moduleId | clé | Valeur FR |
|---|---|---|
| `general` | `suite.nav.sections.general` | Général |
| `platform` | `suite.nav.sections.platform` | Plateforme |
| `dev` | `suite.nav.sections.dev` | Administration |
| `editorial` | `suite.nav.sections.editorial` | Editorial |
| `crm` | `suite.nav.sections.crm` | CRM |
| `erp` | `suite.nav.sections.erp` | ERP |
| `ecommerce` | `suite.nav.sections.ecommerce` | E-commerce |
| `billing` | `suite.nav.sections.billing` | Facturation |
| `ged` | `suite.nav.sections.ged` | GED |
| `photo` | `suite.nav.sections.photo` | Photo |
| `project` | `suite.nav.sections.project` | Projet |
| `planning` | `suite.nav.sections.planning` | Planning |
| `hr` | `suite.nav.sections.hr` | RH |

## Cas particuliers

- **Dashboard** : utilise `suite.nav.sections.general` comme premier crumb (moduleId `general`).
- **Profil** : page standalone sans section parente - pas de premier crumb section.
- **Editorial** : malgré plusieurs sous-sections dans la sidemenu (`home`, `posts`, `terms`), toutes les pages Editorial utilisent `suite.nav.sections.editorial`. Pas de distinction par sous-section.
- **GED Catégories** : `GED / Documents(→documents_url) / Catégories` - les catégories sont sous-éléments de documents.

## Pourquoi

**Why:** Cohérence de navigation sur 50+ templates suite. Sans ce premier fil, l'utilisateur perd le contexte de section.

**How to apply:** À chaque nouveau template backend, vérifier que la clé `suite.nav.sections.{moduleId}` existe dans le YAML du module avant d'écrire le breadcrumb.
