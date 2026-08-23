---
name: convention_locale_options
description: Toujours importer LOCALE_OPTIONS depuis @core/utils/locales.js - ne jamais redéclarer la liste inline
metadata:
  type: feedback
---

## Règle

Pour tout sélecteur de locale dans une vue ou un composable Vue, **toujours importer `LOCALE_OPTIONS`** depuis `@core/utils/locales.js`. Ne jamais redéclarer la liste inline.

```js
import { LOCALE_OPTIONS } from "@core/utils/locales.js";

// Dans le template :
// <AppSelect v-model="form.locale" :options="LOCALE_OPTIONS" />
```

Les locales supportées sont **uniquement `fr` et `en`**, alignées avec `LocaleEnum` PHP côté backend.

## Pourquoi

Une liste inline (`[{ value: 'fr', label: 'Français' }, ...]`) duplique la source de vérité. Si une locale est ajoutée ou retirée du backend (`LocaleEnum`), il faut modifier tous les fichiers qui redéclarent la liste. `LOCALE_OPTIONS` est l'unique source - une seule mise à jour propage partout.

## Comment l'appliquer

- Tout `AppSelect` pour le champ locale → importer `LOCALE_OPTIONS`.
- Ne jamais écrire `[{ value: 'fr', ... }, { value: 'en', ... }]` directement dans un composable ou un template.
- Si `@core/utils/locales.js` n'existe pas encore dans un contexte client, le créer en miroir avec les mêmes valeurs que `LocaleEnum` PHP.
