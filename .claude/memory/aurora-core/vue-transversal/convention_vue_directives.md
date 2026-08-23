# Convention : directives Vue - `v-on:` jamais `@`

## Règle

**Toujours utiliser la forme longue `v-on:` pour les events**, jamais
le raccourci `@`.

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

Le `:` pour `v-bind` est tellement répandu que la forme longue
casserait la lisibilité. On le garde.

```vue
<!-- ✅ -->
<AppInput :label="t('field.name')" :error="errors.name ?? ''" />
<AppModal :show="open" :title="title" />

<!-- Pas obligatoire mais accepté aussi : -->
<AppInput v-bind:label="t('field.name')" />
```

## Pourquoi

- **Cohérence** : tout le code Aurora utilise `v-on:` (Agencies,
  Project, Media, etc.). Mélanger `@click` et `v-on:click` rend la
  lecture pénible.
- **Lecture explicite** : `v-on:click` se lit "j'écoute l'event click",
  alors que `@click` est plus implicite. Pour des juniors / nouveaux
  arrivants, la forme longue documente mieux.
- **Recherche** : `grep "v-on:click"` retrouve toutes les occurrences ;
  `grep "@click"` peut récupérer des faux positifs (mentions Twig,
  texte, etc.).

## Comment l'appliquer

### À l'écriture

Réflexe : tape `v-on:` quand tu déclares un listener. L'IDE auto-
complète si configuré (Volar / Vue Language Features).

### À l'audit / refacto

```bash
# Trouver tous les @click etc. dans .vue
grep -rEn '@(click|submit|change|input|keydown|keyup|close|focus|blur|update)' \
    src/Core/assets/ src/Module/*/assets/ --include="*.vue" \
  | grep -v node_modules | grep -v ".test."
```

Devrait retourner 0. Sinon refacto vers `v-on:`.

### Modifiers

Les modifiers (`.prevent`, `.stop`, `.enter`, etc.) suivent la même
règle :

```vue
<!-- ✅ -->
<form v-on:submit.prevent="onSubmit">
<input v-on:keydown.enter="search">
<button v-on:click.stop="action">

<!-- ❌ -->
<form @submit.prevent="onSubmit">
```

## Source

Convention validée par l'utilisateur le 2026-05-08. Aurora-core utilise
déjà `v-on:` dans tous les modules de référence (Agencies, Project,
Media, Forms, Galleries, etc.). À appliquer systématiquement aux
nouveaux composants.
