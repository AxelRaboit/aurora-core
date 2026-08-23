---
name: pattern_extend_seo
description: Côté aurora-client, configurer le SEO d'une page publique = override `{% block seo_define %}{% do seo({...}) %}{% endblock %}` dans la passerelle Twig.
metadata:
  type: feedback
---

## Règle

Toute page publique (theme custom ou passerelle d'un module client) configure
son `<head>` SEO via **un seul appel** à la fonction Twig `seo({...})` dans
`{% block seo_define %}` :

```twig
{% extends 'Frontend/themes/default/layout.html.twig' %}

{% block seo_define %}{% do seo({
    title: agency.name,
    description: agency.description,
    image: agency.coverImage,
    type: 'profile',
}) %}{% endblock %}

{% block body %}
    <div {{ vue_component('App/frontend/AgencyApp', { agency: agency }) }}></div>
{% endblock %}
```

`seo()` est fournie par `SeoExtension` (aurora-core) - toujours disponible.

## Pourquoi

Le `<head>` est rendu par `partials/head.html.twig` **inclus** par le layout.
Les `{% block %}` à l'intérieur d'un partial inclus ne sont pas overridables ;
les `{% set %}` au top-level ne traversent qu'un seul niveau d'extends. La
fonction `seo()` contourne ces deux limites via un side-effect sur la request
(le head appelle `seo_current()` pour relire).

Doc canonique : [`convention_seo_head.md`](../../../docs/aurora-shared/convention_seo_head.md).

## Comment l'appliquer

### Cas 1 - Theme custom

```bash
mkdir -p templates/Frontend/themes/mon-theme/editorial/home
```

```twig
{# templates/Frontend/themes/mon-theme/editorial/home/index.html.twig #}
{% extends 'Frontend/themes/default/layout.html.twig' %}

{% block seo_define %}{% do seo({
    title: 'Accueil',
    description: 'Bienvenue chez nous',
}) %}{% endblock %}

{% block body %}
    {# … #}
{% endblock %}
```

### Cas 2 - Passerelle d'un module client

Même pattern, dans `src/Module/<Client>/templates/frontend/…/index.html.twig` (ou - pour backward compat - `templates/Module/<Client>/frontend/…/index.html.twig`). Le
controller du client utilise `ViewBuilder::baseView()` (aurora-core) et passe
les variables habituelles (`context`, `locale`, `alternates`…).

### Cas 3 - Override total de `partials/head.html.twig`

Si le client a besoin d'injecter Google Analytics, Cookiebot, ou un JSON-LD
global : copier le head dans `templates/Frontend/themes/<slug>/partials/head.html.twig`
et **continuer à lire `seo_current()`** pour préserver les meta SEO standards.

### Paramètres acceptés par `seo({...})`

`title`, `description`, `image` (Media / array sérialisé / URL), `canonical`,
`type` (`'website'` / `'article'` / `'product'` / `'profile'` / …),
`twitterCard`, `noindex` (bool), `extraMeta` (HTML brut), `jsonLd` (array).

**Pages privées** (panier, account, login client, … toute page derrière auth
ou non-indexable) : toujours `noindex: true` dans l'appel `seo()`.

## Paramètres globaux configurables en backend

`/backend/dev/parameters`, groupe **SEO** :

- `seo_title_template` - concat titre + siteName
- `seo_default_description` - fallback meta description
- `seo_default_og_image` - fallback og:image (Media reference)
- `seo_twitter_handle` - `twitter:site`
