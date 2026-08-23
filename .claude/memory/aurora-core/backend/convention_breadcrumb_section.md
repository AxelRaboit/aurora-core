# Convention : breadcrumb - premier fil = section de navigation

## Règle

Chaque `page_header` backend commence son breadcrumb par le label de section :

```twig
{label: 'backend.nav.sections.<moduleId>'|trans},
{label: 'backend.nav.xxx'|trans},              {# index : sans href #}
{label: 'backend.nav.xxx'|trans, href: path('...')},  {# sous-page : avec href vers la liste #}
{label: element.name},                         {# page détail : nom de l'entité #}
```

**Ne jamais utiliser `backend.modules.*` dans un breadcrumb** - ces clés dupliquent souvent le label de section (ex : `backend.modules.crm` = "CRM" = `backend.nav.sections.crm`). Utiliser directement le label de liste (`backend.nav.contacts`, etc.).

## Clés de section disponibles (moduleId → clé → valeur FR)

| moduleId | clé | Valeur FR |
|---|---|---|
| `general` | `backend.nav.sections.general` | Général |
| `platform` | `backend.nav.sections.platform` | Plateforme |
| `dev` | `backend.nav.sections.dev` | Administration |
| `editorial` | `backend.nav.sections.editorial` | Editorial |
| `crm` | `backend.nav.sections.crm` | CRM |
| `erp` | `backend.nav.sections.erp` | ERP |
| `ecommerce` | `backend.nav.sections.ecommerce` | E-commerce |
| `billing` | `backend.nav.sections.billing` | Facturation |
| `ged` | `backend.nav.sections.ged` | GED |
| `photo` | `backend.nav.sections.photo` | Photo |
| `project` | `backend.nav.sections.project` | Projet |
| `planning` | `backend.nav.sections.planning` | Planning |
| `hr` | `backend.nav.sections.hr` | RH |

## Cas particuliers

- **Dashboard** : utilise `backend.nav.sections.general` comme premier crumb (moduleId `general`).
- **Profil** : page standalone sans section parente - pas de premier crumb section.
- **Editorial** : malgré plusieurs sous-sections dans la sidemenu (`home`, `posts`, `terms`), toutes les pages Editorial utilisent `backend.nav.sections.editorial`. Pas de distinction par sous-section.
- **GED Catégories** : `GED / Documents(→documents_url) / Catégories` - les catégories sont sous-éléments de documents.

## Pourquoi

**Why:** Cohérence de navigation sur 50+ templates backend. Sans ce premier fil, l'utilisateur perd le contexte de section.

**How to apply:** À chaque nouveau template backend, vérifier que la clé `backend.nav.sections.{moduleId}` existe dans le YAML du module avant d'écrire le breadcrumb.
