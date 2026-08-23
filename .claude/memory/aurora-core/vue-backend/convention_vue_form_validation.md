---
name: Validation frontend - pattern useForm + required() + :error
description: Pattern standard de validation côté Vue : useForm, required() de validators, binding :error sur chaque AppInput, reset du loading sur tous les chemins
type: feedback
---

## Règle

Toute validation de formulaire Vue doit suivre ce pattern unique, sans exception :

```js
import { useForm } from '@shared/composables/form/useForm.js';
import { required, url, email } from '@shared/utils/validation/validators.js';
// compose est aussi disponible pour chaîner plusieurs validateurs sur un même champ
import { useI18n } from 'vue-i18n';

// Dans le composant ou le composable (jamais t en paramètre) :
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

Et dans le template, **chaque AppInput doit avoir son `:error`** :

```vue
<AppInput
    v-model="form.title"
    :label="t('...')"
    :placeholder="t('...')"
    :error="errors.title"
    required
/>
```

**Why:** Lors de la création du module Vault (2026-05-09), les premières implémentations utilisaient :
- De la validation inline (`error.value = 'message'` → une seule erreur globale, pas bindée par champ)
- `t` passé en paramètre aux composables et fonctions (`submitCreate(t)`, `openCreate(t)`) - anti-pattern, `useI18n()` doit être appelé **à l'intérieur** du composable
- Des clés de traduction inventées (`shared.validation.required`, `backend.shared.weak`) qui n'existent pas

Ces patterns ne sont jamais utilisés dans les autres modules.

**How to apply:**
1. `useI18n()` toujours à l'intérieur du composable ou du composant - jamais passé en paramètre
2. `useForm()` pour gérer `errors`, `validate`, `clearErrors`, `setErrors`
3. `required()` / `url()` / `email()` de `@shared/utils/validation/validators.js` - jamais de validation inline
4. Chaque `AppInput` / `AppTextarea` / `AppSelect` **validé** reçoit `:error="errors.<field>"` - les champs non-validés (sélecteurs de durée, filtres, etc.) n'en ont pas besoin
5. Pour les erreurs serveur : `setErrors(translateServerErrors(t, data.errors))`

## Loading state - reset sur tous les chemins

Quand on gère `loading` manuellement (hors `useRequest`), s'assurer qu'il est remis à `false` **aussi bien en cas d'erreur que de succès** via les callbacks :

```js
// Pattern : émettre des callbacks onError / onSuccess
emit('unlock', {
    masterPassword: masterPassword.value,
    onError: () => { loading.value = false; setErrors({ field: t('...') }); },
    onSuccess: () => { loading.value = false; },
});
```

`useRequest` gère ça automatiquement via `finally`. Pour les flows custom (ex: unlock vault), utiliser les callbacks ou un try/finally explicite.
