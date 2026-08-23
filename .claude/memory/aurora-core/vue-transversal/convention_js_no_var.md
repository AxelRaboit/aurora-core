---
name: convention-js-no-var
description: JS - toujours utiliser const/let, jamais var (y compris dans les scripts inline Twig et les modules side-effect)
metadata:
  type: feedback
---

## Règle

Tout code JavaScript écrit dans le projet (modules ES, scripts inline Twig,
controllers Stimulus, side-effect bootstraps, composables Vue) doit utiliser
`const` ou `let` exclusivement. **Jamais `var`.**

## Pourquoi

Préférence utilisateur explicite. Hoisting et function-scope de `var` sont
des sources de bugs subtils ; `const`/`let` avec block-scope sont la norme
moderne et expriment l'intention de mutation (`let`) vs immuabilité (`const`).

## Comment l'appliquer

- Nouveaux fichiers JS : `const` par défaut, `let` quand réassignation requise.
- Scripts inline dans des templates Twig : pareil, même si le navigateur cible
  supporte `var`. Préférer encore mieux : extraire vers un fichier `.js`
  importé depuis `app.js` (cf. `src/Core/assets/shared/utils/loader.js` pour le pattern
  side-effect module).
- Refacto de code existant : remplacer les `var` quand on touche le fichier.

Exemple pattern propre (extrait `src/Core/assets/shared/utils/loader.js`) :
```js
const LOADER_ID = "aurora-loader";

function initLoader() {
    const loader = document.getElementById(LOADER_ID);
    if (!loader) return;
    let done = false;
    const hide = () => { /* ... */ };
}
```

Anti-pattern à éviter dans les Twig :
```twig
<script>
    var loader = document.getElementById(...); // ❌
</script>
```

Voir aussi [[convention-no-inline-js-twig]] (à créer si pattern devient
récurrent : pas de logique JS dans les Twig, extraire vers modules ES).
