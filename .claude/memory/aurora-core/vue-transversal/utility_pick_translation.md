---
name: utility_pick_translation
description: pickTranslation et translatedField centralisent la résolution de translations Doctrine - fallback chain locale → en → première dispo.
metadata:
  type: feedback
---

## Règle

Pour résoudre une translation côté Vue, utiliser exclusivement :

```js
import {
  pickTranslation,
  translatedField,
} from '@/shared/utils/i18n/pickTranslation.js'

// Renvoie l'objet translation entier (ou null)
const tr = pickTranslation(post, currentLocale, 'en')

// Renvoie directement un champ (ou null)
const title = translatedField(post, 'title', currentLocale, 'en')
```

### Fallback chain (obligatoire, dans cet ordre)

1. translation pour `locale`
2. translation pour `fallbackLocale` (par défaut `'en'`)
3. **première translation disponible** dans le tableau
4. `null`

Le 3ᵉ niveau est essentiel : une entité peut n'avoir aucune translation FR ni
EN mais une DE - il faut afficher quelque chose plutôt que rien.

## Pourquoi

- Avant : chaque app Vue refaisait son `.find(t => t.locale === …)` avec des
  fallbacks inconsistants. Drift garanti.
- Le contrat (`translations: [{ locale, …fields }]`) est universel côté
  serializer Aurora → une seule utilité côté client.
- Signature `(entity, field, locale, fallback)` ⇒ pas de `?.title` ambigu, pas
  de surprise quand `entity.translations` est `undefined`.

## Comment l'appliquer

1. Toute lecture d'un champ traduit côté Vue → `translatedField(…)`. Pas de
   `.find()` inline.
2. Pour itérer ou afficher plusieurs champs d'une même translation, capturer
   d'abord avec `pickTranslation(…)`.
3. Ne pas réécrire la fallback chain : si elle évolue (ex: prendre le locale
   site avant `en`), modifier le util partagé.
