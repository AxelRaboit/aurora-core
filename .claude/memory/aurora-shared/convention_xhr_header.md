---
name: convention_xhr_header
description: useRequest envoie X-Requested-With sur tous les appels - les controllers Symfony détectent les XHR via ce header pour retourner JSON
metadata:
  type: feedback
---

## Règle

`useRequest` (et `useFrontendRequest`) envoient automatiquement le header `X-Requested-With: XMLHttpRequest` sur tous les appels HTTP. **Ne jamais utiliser `fetch()` directement** - cela omettrait ce header et casserait la détection XHR côté Symfony.

Les controllers Symfony utilisent `$request->isXmlHttpRequest()` pour retourner JSON au lieu de HTML :

```php
if ($request->isXmlHttpRequest()) {
    return $this->json(['success' => true, ...]);
}
// Sinon : redirect ou render HTML
```

## Pourquoi

Sans ce header, un appel Vue vers un endpoint Aurora retourne du HTML (la réponse navigateur standard) au lieu de JSON. Le composable crashe silencieusement ou reçoit du HTML non parseable. Le header est le contrat entre le frontend Vue et le backend Symfony.

## Comment l'appliquer

- Tout appel HTTP depuis Vue → passer par `useRequest` ou `useFrontendRequest`.
- Tout controller Aurora qui répond différemment selon le contexte → vérifier `$request->isXmlHttpRequest()`.
- Ne jamais ajouter le header manuellement dans un `fetch()` brut pour contourner - c'est le signe qu'il faut migrer vers `useRequest`.
