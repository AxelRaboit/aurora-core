---
name: convention_no_raw_fetch
description: Interdiction du fetch() brut dans les composables et vues admin - toujours useRequest. Pour le frontend public, utiliser useFrontendRequest.
metadata:
  type: feedback
---

## Règle

**Ne jamais écrire `await fetch(url, { ... })` directement** dans un composable ou une vue Vue.

Utiliser à la place les composables HTTP du projet :

### Admin backend → `useRequest`
```js
import { useRequest } from "@/shared/composables/http/useRequest.js";

const { loading, request } = useRequest();
const data = await request(url, payload);          // POST JSON
const data = await request(url);                   // POST sans body
const data = await request(url, null, 'DELETE');   // autre méthode
```
- Gère automatiquement : loading guard, toast sur erreur HTTP, AbortSignal
- Retourne `null` si erreur réseau/HTTP → le caller fait `if (!data) return;`

### Frontend public → `useFrontendRequest`
```js
import { useFrontendRequest } from "@/shared/composables/http/useFrontendRequest.js";

const { loading, request } = useFrontendRequest();
const data = await request(url, payload);
```
- Pas de toasts automatiques - erreurs gérées inline par le caller
- Utilisé dans Photo frontend, FormRenderApp, PostCommentsApp, etc.

### Patterns hauts niveau (préférer quand applicable)
- `useFormAction({ rules, url, body, onSuccess })` - pour les actions de formulaire create/edit
- `useFormModal({ empty, fromEntity, createUrl, editUrl, ... })` - pour les modales create+edit
- `useServerErrors` - pour la gestion des erreurs serveur (translate + toast _global + setErrors)

## Pourquoi

Un `fetch()` brut ignore le loading guard, duplique la gestion d'erreur, et ne produit pas de toast cohérent.

## Comment l'appliquer

- À chaque nouveau composable/vue : chercher `await fetch(` et remplacer
- Si `useRequest` n'est pas encore importé dans le fichier → l'ajouter
- Exceptions légitimes : `useFrontendRequest.js` lui-même (c'est le wrapper), `useFormRequest.js` (idem), et les EditorJS blocks (contexte sans composables Vue)
- **Exception : sauvegarde de préférence sans attente.** Un réglage d'interface
  qu'on bascule (pliage de la sidemenu, affichage des descriptions) écrit son
  `void fetch(...)` sans `await`, et c'est délibéré : les trois services que
  `useRequest` rend sont exactement ceux qu'on ne veut pas ici. Le garde de
  chargement empêcherait deux clics rapprochés, alors qu'un interrupteur doit
  suivre le doigt. Le toast d'erreur annoncerait « impossible d'enregistrer votre
  préférence de menu », ce que personne n'a besoin de lire. Et il n'y a rien à
  faire de la réponse : l'état est déjà à l'écran, et le pire cas d'un échec est
  le menu qui revient dans sa forme précédente à la page suivante, soit exactement
  ce qui se passait avant que la préférence soit persistée.
  Concerne `useSidemenuCollapse.js` et `useSidemenuDescriptions.js`. Un nouveau
  cas doit remplir les trois conditions : rien à attendre, rien à afficher en cas
  d'échec, et un échec sans conséquence.
- Vérification rapide (core) : `grep -rn "await fetch\b" src/ --include="*.js" --include="*.vue"` ; (client) : `grep -rn "await fetch\b" assets/ --include="*.js" --include="*.vue"`
