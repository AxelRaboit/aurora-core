---
name: convention_js_privacy
description: Privacy JS - # pour les classes ES2022, jamais _; les variables module-level non exportées sont déjà privées
metadata:
  type: feedback
---

## Règle

### Dans une `class`

**Toujours `#name`** (private fields/methods natifs ES2022) pour représenter de la privacy. **Jamais `_name`**.

```js
// ✅ Bon
class Cache {
    #store = new Map();

    get(key) {
        return this.#store.get(key);
    }

    #cleanup() { /* ... */ }
}

// ❌ Mauvais
class Cache {
    _store = new Map();   // techniquement public
}
```

### Hors classe (module-level, composable)

Le `#` ne marche pas hors classe. Ne pas exporter une variable la rend déjà inaccessible depuis d'autres modules - c'est suffisant. **Pas de préfixe `_`.**

```js
// useNotifications.js
let sharedState = null;     // ✅ pas exporté = privé au module
let refCount = 0;

export function useNotifications(paths) {
    // sharedState, refCount accessibles ici uniquement
}
```

## Pourquoi

- `#` est strict : TypeError si on tente d'y accéder depuis l'extérieur.
- `_` est trompeur : juste une convention, le code appelant peut quand même y toucher.
- Les variables module-level non exportées sont déjà protégées par le scope ES module - le préfixe `_` est du bruit visuel inutile.

## Comment l'appliquer

- Nouvelle classe avec membres internes → `#`.
- Composable / fonction utilitaire avec état → variable module-level non exportée, sans préfixe.

```bash
# Audit (core : src/, client : assets/)
grep -rEn "class\s+\w+|^\s+_[a-z]" src/ assets/ --include="*.js" --include="*.vue" \
  | grep -v node_modules | grep -v ".test."
```
