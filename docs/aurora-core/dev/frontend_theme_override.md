# Frontend — Système de thèmes et override de templates

## Principe

Le site public (frontend) utilise un système de thèmes basé sur `ThemeResolver`.
Chaque template frontend est résolu ainsi :

1. Un thème est marqué `active = true` en BDD.
2. `ThemeResolver::resolve('editorial/home')` cherche d'abord
   `<project>/templates/Frontend/themes/<slug>/editorial/home.html.twig`
   (les thèmes custom restent côté projet — c'est de la data utilisateur).
3. S'il existe → ce fichier est utilisé. Sinon → fallback sur
   `Frontend/themes/default/editorial/home.html.twig`, résolu via le null
   namespace vers le bundle (`vendor/.../src/Core/templates/Frontend/themes/default/`).

Un thème custom n'a donc besoin de ne contenir **que les templates qu'il override**.
Tout ce qui n'est pas présent dans le thème custom tombe sur `default`.

---

## Override du thème `default` depuis un package de module

Le mécanisme ci-dessus est réservé aux **clients** : il passe par un slug de
thème différent, et `ThemeResolver` ne regarde `<project>/templates/` que pour
un slug ≠ `default`. Un **package de module** (`aurora-editorial`, …) qui veut
enrichir le thème `default` lui-même — typiquement pour ajouter au layout un
chrome que le core ne peut pas connaître — passe par un autre canal.

Tout fichier déposé dans `<module>/templates/_theme/` est enregistré par
`AbstractAuroraModuleBundle` sous le **null namespace**, devant celui du core
(les bundles de module `prepend` après `AuroraBundle`, donc leurs paths passent
en premier). Il masque alors son homonyme du core pour toute l'application.

```
aurora-editorial/templates/_theme/Frontend/themes/default/layout.html.twig
  masque
vendor/axelraboit/aurora/src/Core/templates/Frontend/themes/default/layout.html.twig
```

Intérêt : les templates de page du bundle font `{% extends
'Frontend/themes/default/layout.html.twig' %}` **en dur**. Comme l'override
réutilise le même nom logique, ils récupèrent la nouvelle version sans être
modifiés — pas besoin de recopier chaque page.

**Deux règles à respecter.**

1. **Étendre via `@AuroraTheme`, jamais via le nom logique.** Depuis l'intérieur
   de l'override, `{% extends 'Frontend/themes/default/layout.html.twig' %}` se
   résout vers l'override lui-même et boucle à l'infini. L'alias `@AuroraTheme`
   pointe sur `src/Core/templates/Frontend/themes/` du bundle et donne accès à
   l'original :

   ```twig
   {% extends '@AuroraTheme/default/layout.html.twig' %}

   {% block frontend_header %}…{% endblock %}
   ```

   En ne surchargeant que les blocs voulus, l'override continue de suivre les
   évolutions du core sur tout le reste.

2. **Ne pas dépendre des variables posées par le corps du parent.** Un bloc
   surchargé reçoit bien le contexte, mais s'appuyer dessus couple l'override à
   l'ordre interne du template parent. Recalculer localement ce dont on a besoin.

Le dossier est volontairement nommé et isolé : enregistrer tout
`<module>/templates/` sous le null namespace laisserait n'importe quel module
masquer silencieusement n'importe quel template du core avec lequel il
partagerait un chemin. Ici, masquer est un geste explicite.

**Exemple en place** : `aurora-editorial` remplace ainsi le layout `default` —
volontairement dépourvu de navigation côté core — par une version qui rend ses
menus. Voir `aurora-editorial/templates/_theme/`.

---

## Structure des thèmes

```
<bundle>/src/Core/templates/Frontend/themes/
  default/                    ← thème de référence livré avec Aurora (bundle)
    layout.html.twig
    partials/
    auth/
    editorial/
    ecommerce/
    photo/
    ged/

<project>/templates/Frontend/themes/
  mon-theme/                  ← thème custom client (override partiel)
    layout.html.twig          ← override du layout (nav, footer, head)
    editorial/
      home.html.twig          ← override de la home uniquement
```

---

## Créer un thème custom

### 1. Créer le dossier

```bash
mkdir -p templates/Frontend/themes/mon-theme/
```

### 2. Copier uniquement les templates à modifier

Ne copier que ce qu'on veut changer. Exemple — changer uniquement le layout :

```bash
cp templates/Frontend/themes/default/layout.html.twig \
   templates/Frontend/themes/mon-theme/layout.html.twig
```

Éditer le fichier copié librement.

### 3. Enregistrer le thème en BDD

```bash
php bin/console dbal:run-sql "
  INSERT INTO core_themes (id, slug, name, active, config)
  VALUES (NEXTVAL('seq_core_theme_id'), 'mon-theme', 'Mon Thème', false, '{}')
  ON CONFLICT (slug) DO NOTHING
"
```

### 4. Activer le thème

Un seul thème actif à la fois.

```bash
# Désactiver tous les thèmes, activer le nouveau
php bin/console dbal:run-sql "UPDATE core_themes SET active = false"
php bin/console dbal:run-sql "UPDATE core_themes SET active = true WHERE slug = 'mon-theme'"
```

Revenir au thème par défaut :

```bash
php bin/console dbal:run-sql "UPDATE core_themes SET active = false"
php bin/console dbal:run-sql "UPDATE core_themes SET active = true WHERE slug = 'default'"
```

Alternativement, les thèmes sont gérables depuis le backend admin :
`/backend/configuration/themes`.

---

## Templates overridables

Tous les templates sous `Frontend/themes/default/` sont overridables :

| Chemin | Rôle |
|---|---|
| `layout.html.twig` | Layout principal (nav, footer, `<head>`, **block `seo_define`**) |
| `partials/head.html.twig` | Meta SEO, OG, Twitter Cards, scripts — lit `seo_current()` |
| `auth/layout.html.twig` | Sub-layout auth (header simplifié) |
| `auth/{login,register,register_confirm,forgot_password,reset_password,verify_email,account}.html.twig` | Pages auth |
| `editorial/home/index.html.twig` | Accueil éditorial |
| `editorial/post/index.html.twig` | Article/page |
| `editorial/archive/index.html.twig` | Archive (liste par type) |
| `editorial/term/index.html.twig` | Terme de taxonomie |
| `editorial/form/index.html.twig` | Formulaire public |
| `ecommerce/shop/{index,product,category,tag}.html.twig` | Boutique |
| `ecommerce/{cart,checkout}.html.twig` | Panier / Commande |
| `ecommerce/order/show.html.twig` | Récap commande |
| `ecommerce/account/orders.html.twig` | Compte — commandes |
| `photo/gallery/layout.html.twig` | Layout galerie photo |
| `photo/gallery/{index,unlock}.html.twig` | Vue galerie photo |
| `ged/documents/index.html.twig` | Bibliothèque GED |

**SEO** : voir [`convention_seo_head.md`](convention_seo_head.md) — chaque passerelle
override `{% block seo_define %}{% do seo({...}) %}{% endblock %}` ; ne **pas** utiliser
`{% block title %}` / `{% block og_image %}` etc. (code mort silencieux, cf. la doc).

---

## `ThemeResolver::resolveAll()`

`resolveAll()` retourne une map `nom → chemin résolu` des templates principaux.
Cette map est passée à Vue via `themeTemplates` pour que les includes inter-templates
suivent le thème actif (un `_post_card` overridé doit être utilisé même depuis
un template `home` overridé).

```twig
{# Dans un template custom qui inclut un partial #}
{{ include(themeTemplates['editorial/_post_card'], {post: post, locale: locale}) }}
```

Si le thème custom override `_post_card`, c'est sa version qui sera incluse.

> **Note** : `resolveAll()` ne couvre que les templates Editorial + layout.
> Les templates Ecommerce/Auth/Photo/GED n'en font pas encore partie.

---

## Exemple minimal : bannière custom sur la home

`templates/Frontend/themes/demo/editorial/home/index.html.twig` :

```twig
{% extends 'Frontend/themes/default/layout.html.twig' %}

{% block seo_define %}{% do seo({title: 'Accueil — démo'}) %}{% endblock %}

{% block body %}
    <div class="mb-6 p-4 bg-amber-500/20 border border-amber-500/40 rounded-lg text-sm">
        Bienvenue sur le thème Demo !
    </div>

    {# Copier le reste du contenu depuis default/editorial/home/index.html.twig #}
{% endblock %}
```

Seul `editorial/home/index.html.twig` est overridé — tous les autres templates
(archive, post, shop, auth…) continuent d'utiliser `default`.
