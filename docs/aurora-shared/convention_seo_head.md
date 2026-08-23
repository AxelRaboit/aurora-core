# Frontend - Convention SEO / Open Graph / Twitter Cards

Aurora rend ses pages publiques en server-side Twig minimal qui monte une app Vue.js.
Les crawlers Google (en mode fallback) et **surtout** les previews sociaux
(Facebook, LinkedIn, Twitter/X, Slack, Discord…) n'exécutent **pas** le JavaScript.
Sans meta server-side, le site est invisible sur les partages sociaux et faiblement
référencé. Cette convention décrit comment chaque passerelle Twig publique configure
son SEO via la fonction Twig `seo({...})`.

---

## 1. API en une ligne

Chaque passerelle override le block `seo_define` du layout pour appeler `seo({...})` :

```twig
{% extends 'Frontend/themes/default/layout.html.twig' %}

{% block seo_define %}{% do seo({
    title: listing.seoTitle ?? listing.displayTitle,
    description: listing.seoDescription ?? listing.marketingDescription,
    image: listing.featuredImage,
    type: 'product',
}) %}{% endblock %}

{% block body %}
    <div {{ vue_component('ecommerce/frontend/ShopProductApp', { listing: listing }) }}></div>
{% endblock %}
```

Le head produit automatiquement :
- `<title>Chaussure running - MonShop</title>` (template `SeoTitleTemplate` appliqué)
- `<meta name="description" content="…">`
- `<meta property="og:title|description|type|site_name|url|image|locale|locale:alternate" …>`
- `<meta name="twitter:card|site|title|description|image" …>` (handle Twitter global)
- `<link rel="canonical" href="…">`
- `<meta name="robots" content="noindex, nofollow">` si `noindex: true`
- `<script type="application/ld+json">…</script>` si `jsonLd` passé

---

## 2. Paramètres acceptés par `seo({...})`

Toutes optionnelles. Defaults raisonnables appliqués automatiquement.

| Clé           | Type                                | Default                                          | Effet                                                          |
|---------------|-------------------------------------|--------------------------------------------------|----------------------------------------------------------------|
| `title`       | string                              | `context.siteName`                               | Appliqué dans `SeoTitleTemplate` (`{title} - {siteName}`)      |
| `description` | string                              | `SeoDefaultDescription` ou `context.siteDescription` | Meta description / og / twitter                            |
| `image`       | Media / array sérialisé / URL       | `SeoDefaultOgImage` ou logo                       | `og:image` / `twitter:image`. URLs relatives → absolues auto. |
| `canonical`   | string (path ou URL)                | `context.siteUrl ~ app.request.pathInfo`         | `<link rel="canonical">` ET `og:url`. Path relatif accepté.   |
| `type`        | string                              | `'website'`                                      | `og:type` (`'article'`, `'product'`, `'profile'`, …)          |
| `twitterCard` | string                              | `'summary_large_image'` si image, sinon `'summary'` | `twitter:card`                                              |
| `noindex`     | bool                                | `false`                                          | `<meta name="robots" content="noindex, nofollow">`            |
| `extraMeta`   | string (HTML brut)                  | `''`                                             | Injecté après les meta standards (`article:*`, `product:*`…) |
| `jsonLd`      | array                               | `null`                                           | Sérialisé en `<script type="application/ld+json">`            |

**Normalisation auto** :
- `image` : accepte `MediaInterface`, array sérialisé (`{publicUrl: ...}`), ou string. URLs relatives préfixées avec `context.siteUrl`.
- `canonical` : path relatif (`/boutique/foo`) → préfixé avec `context.siteUrl`. URL absolue passée telle quelle.

---

## 3. Mécanique interne (pourquoi un block + un side-effect)

`partials/head.html.twig` est **inclus** par `layout.html.twig` via `{{ include(...) }}` -
pas via `extends`. Deux conséquences importantes pour les contributeurs :

1. **Les `{% block %}` à l'intérieur de `head.html.twig` ne sont pas overridables**
   depuis une passerelle. Tous les anciens `{% block og_image %}`, `{% block canonical %}`
   etc. étaient du **code mort silencieux**. Ne pas les recréer.

2. **Le `{% set %}` au top-level d'une passerelle ne traverse qu'un seul niveau d'extends**.
   Pour une chaîne `auth/login → auth/layout → layout`, le `{% set %}` du leaf est
   silencieusement perdu - seul celui de `auth/layout` (l'enfant direct du layout)
   propage. Vérifié empiriquement.

D'où la mécanique adoptée :
- `seo()` (fonction Twig PHP) résout le payload, le stocke dans les **attributs de la
  request courante**, et le retourne.
- Le layout expose un `{% block seo_define %}{% do seo({}) %}{% endblock %}` rendu
  **avant** l'include du head.
- Chaque passerelle override ce block - les **blocks**, eux, traversent toute la chaîne
  d'extends (testé).
- Le head appelle `seo_current()` qui lit le payload stocké dans la request.

Trade-off : `SeoExtension` porte un état per-request (via `RequestStack`). En mode FPM
(standard) chaque request a une instance vierge, donc safe. En worker-mode
(Swoole/RoadRunner) l'état request est nettoyé entre requêtes - toujours safe car
on lit depuis `Request::$attributes`.

---

## 4. Cookbook par type de page

### 4.1 Page riche (produit, post, gallery)

```twig
{% block seo_define %}{% do seo({
    title: listing.seoTitle ?? listing.displayTitle,
    description: listing.seoDescription ?? listing.marketingDescription,
    image: listing.featuredImage,
    type: 'product',
}) %}{% endblock %}
```

### 4.2 Article / blog post (avec `article:published_time` + JSON-LD)

```twig
{% block seo_define %}{% do seo({
    title: translationData.metaTitle ?? translationData.title,
    description: translationData.metaDescription,
    image: translationData.ogImage ?? featuredMediaData,
    canonical: translationData.canonicalUrl ?: (translationData.slug ? path('editorial_post', {…}) : ''),
    type: 'article',
    noindex: translationData.noindex|default(false),
    jsonLd: translationData.jsonLd|default(null),
    extraMeta: postData.publishedAt ? '<meta property="article:published_time" content="' ~ postData.publishedAt ~ '">' : '',
}) %}{% endblock %}
```

### 4.3 Page privée / fonctionnelle (cart, checkout, login, account)

```twig
{% block seo_define %}{% do seo({title: 'frontend.cart.title'|trans, noindex: true}) %}{% endblock %}
```

> Limite Twig connue : les pages auth extendent `auth/layout` qui extend `layout`.
> Chaque page auth doit donc inclure `noindex: true` dans **son propre** appel `seo()`
> (l'astuce de centraliser dans `auth/layout` ne fonctionne pas - cf. section 3, point 2).

### 4.4 Page simple (home, archive - titre seul suffit)

```twig
{% block seo_define %}{% do seo({title: postType.label}) %}{% endblock %}
```

Ou pour pure home (titre = siteName) - aucun argument :

```twig
{% block seo_define %}{% do seo({}) %}{% endblock %}
```

---

## 5. Override côté client (theme custom)

Un thème custom (`templates/Frontend/themes/<slug>/`) peut override
`partials/head.html.twig` en totalité. Préserver le contrat : lire les mêmes clés
via `seo_current()` (Google Analytics, JSON-LD spécifique, balise
facebook-domain-verification, etc. peuvent venir avant ou après).

Voir [`frontend_theme_override.md`](frontend_theme_override.md) pour le mécanisme de
résolution de thème.

---

## 6. Paramètres globaux d'admin

Dans `/backend/dev/parameters`, groupe **SEO** :

| Paramètre                  | Clé enum                                        | Usage                                      |
|----------------------------|-------------------------------------------------|--------------------------------------------|
| Template de titre SEO      | `SeoTitleTemplate` (`seo_title_template`)       | Concatène titre page + siteName            |
| Description SEO par défaut | `SeoDefaultDescription` (`seo_default_description`) | Fallback pour meta description         |
| Image OG par défaut        | `SeoDefaultOgImage` (`seo_default_og_image`)    | Fallback pour `og:image` / `twitter:image` |
| Handle Twitter / X         | `SeoTwitterHandle` (`seo_twitter_handle`)       | `twitter:site`                             |

---

## 7. Limites connues / TODO

- **`Listing` (Ecommerce) n'a pas tous les champs SEO** que `PostTranslation`
  possède - pas de `ogImage` dédié, `canonicalUrl`, `noindex`, `jsonLd`. Aligner les
  deux entités si Ecommerce devient un usage premier.
- **Variantes locales du `SeoTitleTemplate`** non supportées - un seul template
  pour toutes les locales. Si besoin : étendre le settings model.

---

## 8. Checklist en ajoutant une nouvelle passerelle publique

1. `{% extends 'Frontend/themes/default/layout.html.twig' %}`
2. Override `{% block seo_define %}{% do seo({...}) %}{% endblock %}` avec les
   paramètres pertinents (cf. tableau section 2).
3. `{% block body %}` = un seul `vue_component(...)`.
4. Pages privées (cart, checkout, account, login…) → `noindex: true` obligatoire.
5. **Ne JAMAIS** utiliser `{% block title %}`, `{% block og_image %}`, etc. - ces
   blocks n'existent pas, leur output serait ignoré silencieusement.
