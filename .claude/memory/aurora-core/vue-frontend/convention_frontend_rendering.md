---
name: convention_frontend_rendering
description: Passerelles Vue frontend - chaque passerelle override {% block seo_define %} pour appeler seo({...}). Block body = un vue_component.
metadata:
  type: feedback
---

## Règle

**TOUS les templates frontend sont des passerelles Vue.** Pattern unique :

```twig
{% extends 'Frontend/themes/default/layout.html.twig' %}

{% block seo_define %}{% do seo({
    title: listing.seoTitle ?? listing.displayTitle,
    description: listing.seoDescription,
    image: listing.featuredImage,
    type: 'product',
}) %}{% endblock %}

{% block body %}
    <div {{ vue_component('ecommerce/frontend/ShopProductApp', { listing: listing }) }}></div>
{% endblock %}
```

- **SEO** via la fonction Twig `seo({...})` appelée dans `{% block seo_define %}` (override le block du layout, rendu avant l'include du head). Doc complète : [`docs/aurora-shared/convention_seo_head.md`](../../../docs/aurora-shared/convention_seo_head.md).
- **Body** = un seul `<div {{ vue_component(...) }}></div>`. Aucun markup HTML, aucune logique métier.

## Pourquoi cette mécanique (block + side-effect)

Deux contraintes Twig découvertes empiriquement obligent ce design :

1. **`partials/head.html.twig` est inclus** (pas étendu) par le layout. Donc les `{% block og_image %}`, `{% block canonical %}`, etc. à l'intérieur de `head.html.twig` **ne sont pas overridables** depuis une passerelle. Ces blocks étaient du code mort silencieux avant le refactor de mai 2026.

2. **`{% set %}` au top-level d'un template qui extends ne propage que sur 1 niveau**. Pour la chaîne `auth/login → auth/layout → layout`, un `{% set seo = ... %}` dans `auth/login` est silencieusement **perdu** - seuls les `{% set %}` de l'enfant direct du layout (donc `auth/layout`) propagent. Vérifié.

Les **blocks**, en revanche, traversent toute la chaîne extends. D'où le pattern : `seo()` (PHP Twig function) stocke le payload résolu dans les attributs de la request courante (side-effect), et `head.html.twig` appelle `seo_current()` pour le lire. Le block `seo_define` du layout, rendu avant l'include du head, garantit l'ordre.

## Implications pratiques

- **JAMAIS** utiliser `{% block title %}`, `{% block og_image %}`, `{% block canonical %}`, `{% block robots %}`, `{% block jsonld %}` - ces blocks n'existent pas dans `head.html.twig` ; leur output serait ignoré silencieusement (bug SEO).
- **Pages auth** : chacune doit inclure `noindex: true` dans son propre `seo(...)`. L'astuce "centraliser noindex dans auth/layout" ne marche pas (cf. contrainte 2).
- **Theme custom** : peut override `partials/head.html.twig` mais doit consommer `seo_current()` pour préserver le contrat.

## Comment l'appliquer

1. Nouvelle page frontend → créer `<Module>/frontend/<feature>/index.html.twig` (cf [[structure_template_folders]]), extends `layout.html.twig`.
2. Override `{% block seo_define %}{% do seo({...}) %}{% endblock %}` au minimum avec `title`. Cf. cookbook par type de page dans [`convention_seo_head.md`](../../../docs/aurora-shared/convention_seo_head.md).
3. `{% block body %}` = un seul `vue_component(...)`.
4. Pages privées (panier, checkout, compte, login, order…) → toujours `noindex: true` dans `seo({...})`.

Voir aussi : [[convention_no_bem_tailwind_first]], [[structure_template_folders]].
