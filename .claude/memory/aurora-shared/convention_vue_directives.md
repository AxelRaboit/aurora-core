---
name: convention_vue_directives
description: Directives Vue - toujours v-on: (forme longue) pour les events, jamais @; le raccourci : reste OK pour v-bind
metadata:
  type: feedback
---

## Règle

**Toujours utiliser la forme longue `v-on:` pour les events**, jamais le raccourci `@`.

```vue
<!-- ✅ -->
<AppButton v-on:click="submit">Save</AppButton>
<form v-on:submit.prevent="onSubmit">...</form>
<input v-on:keydown.enter="search">
<AppModal :show="open" v-on:close="open = false">...</AppModal>

<!-- ❌ -->
<AppButton @click="submit">Save</AppButton>
<form @submit.prevent="onSubmit">...</form>
```

### Pour `v-bind` : le raccourci `:` reste OK

```vue
<!-- ✅ les deux formes acceptées -->
<AppInput :label="t('field.name')" :error="errors.name ?? ''" />
<AppInput v-bind:label="t('field.name')" />
```

### Modifiers

Les modifiers suivent la même règle :

```vue
<!-- ✅ -->
<form v-on:submit.prevent="onSubmit">
<input v-on:keydown.enter="search">
<button v-on:click.stop="action">
```

## Pourquoi

- **Cohérence** : tout le code Aurora utilise `v-on:`. Mélanger `@click` et `v-on:click` rend la lecture pénible.
- **Lecture explicite** : `v-on:click` documente mieux l'intention pour les nouveaux arrivants.
- **Recherche** : `grep "v-on:click"` sans faux positifs (mentions Twig, texte, etc.).

## Comment l'appliquer

```bash
# Audit : trouver les @ restants dans les .vue
# Côté core : src/Core/assets/ + src/Module/*/assets/
# Côté client : src/Module/*/assets/ + src/Overrides/ (plus d'assets/client/ depuis 0.5)
grep -rEn '@(click|submit|change|input|keydown|keyup|close|focus|blur|update)' \
    src/ --include="*.vue" \
  | grep -v node_modules | grep -v ".test."
```

Devrait retourner 0. Sinon refacto vers `v-on:`.
