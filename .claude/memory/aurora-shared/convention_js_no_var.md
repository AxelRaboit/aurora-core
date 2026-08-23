---
name: convention_js_no_var
description: JS - toujours utiliser const/let, jamais var (y compris dans les scripts inline Twig et les modules side-effect)
metadata:
  type: feedback
---

## Règle

Tout code JavaScript écrit dans le projet (modules ES, scripts inline Twig, controllers Stimulus, side-effect bootstraps, composables Vue) doit utiliser `const` ou `let` exclusivement. **Jamais `var`.**

## Pourquoi

Préférence utilisateur explicite. Hoisting et function-scope de `var` sont des sources de bugs subtils ; `const`/`let` avec block-scope sont la norme moderne et expriment l'intention de mutation (`let`) vs immuabilité (`const`).

## Comment l'appliquer

- Nouveaux fichiers JS : `const` par défaut, `let` quand réassignation requise.
- Scripts inline dans des templates Twig : pareil, même si le navigateur cible supporte `var`.
- Refacto de code existant : remplacer les `var` quand on touche le fichier.

Anti-pattern à éviter :
```twig
<script>
    var loader = document.getElementById(...); // ❌
</script>
```

Pattern correct :
```js
const loader = document.getElementById(...); // ✅
let done = false;                            // ✅ si réassigné
```
