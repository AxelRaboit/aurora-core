---
name: convention-edit-vs-update-route-naming
description: Aurora controller route naming - `_edit` is the GET edit page (Twig + Vue mount), `_update` is the POST JSON API. Never overload one name for both.
metadata:
  type: feedback
---

**Règle dure** : dans tous les controllers Aurora (core ou client), on
**sépare** explicitement deux concepts qui ont chacun leur nom de route
et leur méthode HTTP :

| Nom de route | HTTP   | Sémantique                                                                                 | Renvoie       |
|--------------|--------|--------------------------------------------------------------------------------------------|---------------|
| `_edit`      | `GET`  | **Page** d'édition standalone - rend un Twig qui monte le composant Vue éditeur            | `Response` (HTML) |
| `_update`    | `POST` | **API** JSON - endpoint que le front fetch pour appliquer la sauvegarde                    | `JsonResponse` |

Exemples canoniques :
- `Photo\Gallery` : `GET /backend/photo/galleries/{id}/edit` + `POST /backend/photo/galleries/{id}/update`
- `Editorial\Post` : pareil depuis le refacto split page/API

**Why** : Aurora est Symfony + Vue avec un split franc page-Twig / API-JSON.
Fusionner les deux dans un seul `edit()` qui handle GET et POST (style
Symfony Maker classique) ne marche pas - la page Twig sert un layout +
monte Vue, l'API renvoie du JSON consommé par fetch. Deux concepts =
deux endpoints = deux noms. Confondre `_edit` et `_update` brouille
cette frontière et casse l'override client (le client peut vouloir
override la page Twig sans toucher à l'API, ou vice-versa).

**How to apply** :
1. **CRUD avec page d'édition dédiée** (Posts, Galleries) → les **deux** routes : `_edit` GET (page Twig) + `_update` POST (API JSON).
2. **CRUD SPA inline** (modal d'édition dans la liste : PostTypes, Taxonomies, Media, Forms, Notes, Vault, Crm, etc.) → **uniquement** `_update` POST. Pas de `_edit` GET parce que la page séparée n'existe pas - l'édition vit dans une modal de l'index, déjà servie par la route `_` (GET index).
3. **Jamais** : route `_edit` POST qui handle la sauvegarde. C'est l'anti-pattern qu'on a corrigé en alignant tout sur `_update`.
4. **Jamais** : route `/{id}/edit` POST (chemin URL avec verbe "edit") pour une API JSON. Le path matche le nom : `/edit` = page, `/update` = API.
5. **Templates Twig** côté liste : props front s'appellent `editPath` (URL vers la page GET `_edit`, pour `<a href>`) et `updatePath` (URL vers l'API POST `_update`, pour `fetch`). Naming aligné avec le nom de route.

**Vérification** :
```bash
# Tout `_edit` doit être GET. Tout `_update` doit être POST.
# Cette commande doit retourner zéro ligne :
grep -rEn "name:\s*'_edit'.*methods.*Post\b" src/ --include="*.php"
grep -rEn "name:\s*'_update'.*methods.*Get\b" src/ --include="*.php"
```

Voir aussi [[convention-thin-controller]] (controllers ultra-fins, qui
rend la séparation `_edit`/`_update` triviale : une méthode rend le
Twig, l'autre délègue au Manager) et [[convention-sfc-thin-presentation]]
(SFC fines, qui rend la séparation page Twig / composant Vue naturelle).
