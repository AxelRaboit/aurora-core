---
name: convention_vue_form_validation
description: Pattern standard de validation côté Vue - useForm + required() de validators + :error sur chaque AppInput
metadata:
  type: feedback
---

## Règle

Toute validation de formulaire Vue doit suivre ce pattern unique :

```js
import { useForm } from '@shared/composables/form/useForm.js';
import { required, url, email } from '@shared/utils/validation/validators.js';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { errors, validate, clearErrors, setErrors } = useForm();

async function submit() {
    if (!validate({
        title: () => required(t('module.entity.errors.title_required'))(form.title),
        website: () => url(t('module.entity.errors.website_invalid'))(form.website),
    })) return;

    // ... requête HTTP
    if (data.success) { ... }
    else setErrors(translateServerErrors(t, data.errors));
}
```

Dans le template, **chaque `AppInput` validé doit avoir son `:error`** :

```vue
<AppInput
    v-model="form.title"
    :label="t('...')"
    :placeholder="t('...')"
    :error="errors.title"
    required
/>
```

## Anti-patterns à éviter

- `error.value = 'message'` → une seule erreur globale, pas bindée par champ
- `t` passé en paramètre aux composables (`submitCreate(t)`) - `useI18n()` doit être appelé **à l'intérieur** du composable
- Clés de traduction inventées pour les validators - utiliser les clés du module

## Règle complémentaire - Loading state

Quand on gère `loading` manuellement (hors `useRequest`), le remettre à `false` **aussi bien en cas d'erreur que de succès** :

```js
emit('unlock', {
    onError: () => { loading.value = false; setErrors({ field: t('...') }); },
    onSuccess: () => { loading.value = false; },
});
```

`useRequest` gère ça automatiquement via `finally`. Pour les flows custom, utiliser les callbacks ou un try/finally explicite.

## Pourquoi

Cohérence de gestion des erreurs sur tous les formulaires Aurora. `useI18n()` appelé à l'intérieur du composable garantit la réactivité i18n (changement de locale en live).

## Comment l'appliquer

1. `useI18n()` toujours à l'intérieur du composable - jamais passé en paramètre.
2. `useForm()` pour gérer `errors`, `validate`, `clearErrors`, `setErrors`.
3. `required()` / `url()` / `email()` de `@shared/utils/validation/validators.js` - jamais de validation inline.
4. Chaque `AppInput` / `AppTextarea` / `AppSelect` **validé** reçoit `:error="errors.<field>"`.
5. Pour les erreurs serveur : `setErrors(translateServerErrors(t, data.errors))`.
