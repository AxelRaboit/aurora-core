# Pattern : override Twig automatique

## Règle

Le bundle Aurora prepend automatiquement les paths côté projet client
devant ses propres paths sous chaque namespace `@<Namespace>`. Un
override client met juste son fichier au bon endroit - résolu en
priorité.

**Pour `@Core` / `@Shared`** : deux paths reconnus -
1. **Nouveau** : `<client>/src/Core/templates/Core/...` ou
   `<client>/src/Core/templates/Shared/...` (aligné sur la convention core
   depuis le déplacement complet de `templates/` sous `src/`)
2. **Legacy** : `<client>/templates/Core/...` ou `<client>/templates/Shared/...`

**Pour `@<Module>`** : deux paths reconnus -
1. **Nouveau** : `<client>/src/Module/<Module>/templates/...`
2. **Legacy** : `<client>/templates/Module/<Module>/...`

**Pour les thèmes frontend** (null namespace) : convention canonique côté
client = `<client>/templates/Frontend/themes/<slug>/...`. Reste un dossier
à la racine du projet client car les thèmes sont **de la data utilisateur**,
pas du code Aurora à étendre.

## Pourquoi

Pas besoin de configurer Twig manuellement pour les namespaces que
Aurora connaît déjà. Le bundle gère le prepend dans
`AuroraBundle::prependExtension()`.

## Comment l'appliquer

### Override d'un template admin Platform

Le template Aurora vit dans
`vendor/axelraboit/aurora/src/Module/Platform/templates/backend/agencies/index.html.twig`
(résolu via `@Platform/backend/agencies/index.html.twig`).

Pour l'override côté client (deux options équivalentes) :

```bash
# Option recommandée - co-localisé
mkdir -p src/Module/Platform/templates/backend/agencies
# Créer src/Module/Platform/templates/backend/agencies/index.html.twig

# Option legacy - supportée pour backward compat
mkdir -p templates/Module/Platform/backend/agencies
# Créer templates/Module/Platform/backend/agencies/index.html.twig
```

Le template client est résolu en priorité dès qu'il existe. Le namespace
Twig `@Platform/...` reste le même - c'est juste l'ordre des paths qui change.

### Mappings utiles

| Namespace Twig | Path Aurora | Override client (recommandé) | Override client (legacy) |
|---|---|---|---|
| `@Core` | `vendor/.../src/Core/templates/Core/` | `src/Core/templates/Core/` | `templates/Core/` |
| `@Shared` | `vendor/.../src/Core/templates/Shared/` | `src/Core/templates/Shared/` | `templates/Shared/` |
| `@Editorial` | `vendor/.../src/Module/Editorial/templates/` | `src/Module/Editorial/templates/` | `templates/Module/Editorial/` |
| `@Crm` | `vendor/.../src/Module/Crm/templates/` | `src/Module/Crm/templates/` | `templates/Module/Crm/` |
| `@Erp` | `vendor/.../src/Module/Erp/templates/` | `src/Module/Erp/templates/` | `templates/Module/Erp/` |
| `@Project` | `vendor/.../src/Module/Project/templates/` | `src/Module/Project/templates/` | `templates/Module/Project/` |
| `@Photo` | `vendor/.../src/Module/Photo/templates/` | `src/Module/Photo/templates/` | `templates/Module/Photo/` |
| `@Billing` | `vendor/.../src/Module/Billing/templates/` | `src/Module/Billing/templates/` | `templates/Module/Billing/` |
| `@Ecommerce` | `vendor/.../src/Module/Ecommerce/templates/` | `src/Module/Ecommerce/templates/` | `templates/Module/Ecommerce/` |
| `@Ged` | `vendor/.../src/Module/Ged/templates/` | `src/Module/Ged/templates/` | `templates/Module/Ged/` |
| `@Platform` | `vendor/.../src/Module/Platform/templates/` | `src/Module/Platform/templates/` | `templates/Module/Platform/` |
| _(null - themes)_ | `vendor/.../src/Core/templates/Frontend/themes/default/` | `templates/Frontend/themes/<slug>/` | - |

### Étendre plutôt que remplacer

Souvent on veut **enrichir** le template Aurora avec un block, pas le
remplacer entièrement :

```twig
{# src/Module/Platform/templates/backend/agencies/index.html.twig (override client) #}
{% extends '@Platform/backend/agencies/index.html.twig' %}

{# Pas du tout possible - on étendrait soi-même, infinite loop. #}
```

❌ Ce pattern ne marche pas (extension récursive). À la place, deux options :

#### Option A : Recopier + modifier

Copier le template Aurora dans le client + modifier ce qu'on veut. Pas
idéal (drift à chaque update Aurora) mais parfois nécessaire pour des
modifications structurelles.

#### Option B : Bloc étendable côté Aurora

Si on a besoin d'un point d'extension récurrent, **demander qu'Aurora
ajoute un block `{% block client_extra %}{% endblock %}`** dans son
template, qu'on peut overrider proprement :

```twig
{# src/Module/Platform/templates/backend/agencies/_extra_client.html.twig (côté client) #}
<div class="custom-stuff">…</div>
```

Et côté Aurora :
```twig
{# Aurora's index.html.twig #}
{% include '@Platform/backend/agencies/_extra_client.html.twig' ignore missing %}
```

Le `ignore missing` permet à Aurora de tolérer l'absence d'override.

## Source

Cf section "Couche 5 / 5.3 Override Twig automatique" du doc convention.
Implémenté dans `Aurora\AuroraBundle::prependExtension()` qui pour
chaque namespace connu enregistre d'abord les paths côté projet
(`<client>/src/Module/<X>/templates/`,
`<client>/templates/Module/<X>/`, `<client>/templates/Core|Shared/`)
puis les paths bundle (`<core>/src/Module/<X>/templates/`,
`<core>/templates/Core|Shared/`).
