# Convention : privacy en JavaScript - `#` pour les classes, jamais `_`

## Règle

### Dans une `class`

**Toujours `#name`** (private fields/methods natifs ES2022) pour
représenter de la privacy. **Jamais `_name`** (qui n'est qu'une
convention textuelle, accessible quand même depuis l'extérieur).

```js
// ✅ Bon
class Cache {
    #store = new Map();

    get(key) {
        return this.#store.get(key);
    }

    #cleanup() {
        // ...
    }
}

// ❌ Mauvais
class Cache {
    _store = new Map();   // techniquement public, juste "convention"
    _cleanup() { /* ... */ }
}
```

`#` rend la propriété **réellement** inaccessible depuis l'extérieur.
TypeError si on tente `cache.#store`. C'est plus sûr et plus correct.

### Hors classe (module-level, fonction, composable)

Le `#` **ne marche pas** - il est syntaxique aux classes uniquement.

Pour des variables module-level (par exemple cache d'un composable), le
fait de **ne pas exporter** la variable suffit à la rendre inaccessible
depuis d'autres modules. C'est déjà privé.

```js
// useNotifications.js
let sharedState = null;     // ✅ pas exporté = privé au module
let refCount = 0;
let pollTimer = null;

export function useNotifications(paths) {
    // sharedState, refCount, pollTimer accessibles ici
    // mais inaccessibles depuis l'extérieur du fichier
}
```

**Pas besoin** de préfixer ces variables d'un `_`. Le préfixe est
inutile (le scope module les protège déjà) et ajoute du bruit visuel.

## Pourquoi

- **`#` est strict** : impossible de contourner. Garantie d'API.
- **`_` est trompeur** : signale juste une convention, mais le code
  appelant peut quand même y toucher → API "private" qui dérive en
  public sans qu'on s'en aperçoive.
- **Nettoyage des linters** : ESLint (no-underscore-dangle) ou les
  préférences d'équipe peuvent flagger les `_*` ; `#` est syntaxique
  donc inviolable.

## Comment l'appliquer

### Code neuf

- Nouvelle classe avec membres internes → `#`.
- Composable / fonction utilitaire avec état privé → variable
  module-level non exportée, sans préfixe.

### Refacto

```bash
# Trouver les usages du _-prefix dans des classes
grep -rEn "class\\s+\\w+|^\\s+_[a-z]" src/Core/assets/ src/Module/*/assets/ --include="*.js" --include="*.vue" \
  | grep -v node_modules | grep -v ".test."
```

Si une classe a des méthodes/champs `_xxx`, refacto vers `#xxx`. Pour
des variables module-level avec `_`, juste retirer le préfixe.

### Cas particulier : éléments DOM ou data-attrs

Les data attributes HTML (`data-internal-id`) ou les noms de fichiers
ne sont pas concernés - c'est du namespace, pas de la privacy JS.

## Source

Convention validée par l'utilisateur le 2026-05-08 après refacto du
composable `useNotifications.js` qui utilisait `_state`, `_refCount`,
`_pollTimer` au niveau module - renommés en `sharedState`, `refCount`,
`pollTimer` (le scope module les rend déjà privés).
