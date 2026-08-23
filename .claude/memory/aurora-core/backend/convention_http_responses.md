---
name: Convention réponses HTTP dans les controllers (JsonResponseTrait + HttpStatusEnum)
description: Toujours utiliser JsonResponseTrait et HttpStatusEnum - jamais $this->json() brut ni de code HTTP en dur
type: feedback
---

## Règle

Dans les controllers, **ne jamais** écrire :
```php
return $this->json(['success' => false, 'error' => '...'], 503);
```

Utiliser à la place les méthodes du `JsonResponseTrait` avec `HttpStatusEnum` :

```php
// Succès
return $this->jsonSuccess(['data' => $data]);

// Erreur générique avec code HTTP nommé
return $this->jsonFailure('pdftk_unavailable', HttpStatusEnum::ServiceUnavailable->value);

// Erreur de validation (422)
return $this->jsonInvalidInput($errors);

// 404 / 403 prédéfinis
return $this->jsonNotFound();
return $this->jsonForbidden();
```

## Pourquoi

- `$this->json(['success' => false], 503)` n'est pas dans l'enveloppe standardisée (`{ success, error }`)
- Les codes HTTP magiques (`503`, `422`) sont illisibles - `HttpStatusEnum::ServiceUnavailable` est auto-documenté
- `jsonFailure` accepte `string|JsonErrorCode` - utiliser une string descriptive pour les codes domaine-spécifiques, `JsonErrorCode` pour les codes universels (`not_found`, `forbidden`…)

## Comment l'appliquer

- Si `HttpStatusEnum` n'a pas le code voulu → l'ajouter (ex: `ServiceUnavailable = 503` a été ajouté lors du module PdfForm)
- Codes 5xx disponibles : `InternalServerError = 500`, `BadGateway = 502`, `ServiceUnavailable = 503`
- Pour les exceptions serveur (binaire manquant, service down) : `jsonFailure($e->getMessage(), HttpStatusEnum::ServiceUnavailable->value)`
