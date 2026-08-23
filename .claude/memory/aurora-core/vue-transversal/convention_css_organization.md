---
name: convention_css_organization
description: Organisation du CSS Aurora - global sous css/base/ importé par app.css, CSS de feature co-localisé à côté du SFC qui le consomme (pas de miroir css/modules/)
metadata:
  type: feedback
---

## Règle

Deux emplacements, selon la portée :

1. **Global** - `src/Core/assets/css/base/`, importé par `app.css`. Réservé
   à ce qui est chargé sur (presque) toutes les pages : tokens de thème,
   base, scrollbar, et les composants shared universels (`input.css`,
   `modal.css`, `loader.css`).
2. **Feature / module** - **co-localisé à côté du SFC** qui le consomme, et
   importé par ce SFC.

```
src/Core/assets/backend/sidemenu/
├── AppSidemenu.vue          # import "./sidemenu.css"
└── sidemenu.css
```

**Il n'y a pas de miroir `src/Core/assets/css/modules/<name>/`** - ce
dossier n'existe pas. Le CSS vit avec le composant qui le consomme, comme
le reste de `src/` depuis 0.5 : on le trouve sans chercher, il se supprime
avec son composant, et un module packagé séparément emporte ses styles.

Documentation complète (autoritaire) :
[`docs/aurora-core/dev/css_conventions.md`](../../../../docs/aurora-core/dev/css_conventions.md).

### Ordre des imports dans un `.vue`

```vue
<script setup>
import "./editor.css";                    // 1. CSS d'abord
import "./blocks.css";

import EditorJS from "@editorjs/editorjs"; // 2. ligne vide, puis JS
</script>
```

CSS d'abord (import side-effect) → ligne vide → JS ensuite. Matche l'ordre
d'application navigateur.

### Inline `<style scoped>` vs fichier externe

- **Tailwind via `:class`** pour 95 % des cas.
- **Fichier externe co-localisé** dès qu'on style du contenu rendu
  (`v-html`, EditorJS, marked) ou qu'on dépasse ~5 règles cohérentes - des
  `:deep()` partout dans un `<style scoped>` sont un anti-pattern.
- **Inline `<style scoped>`** uniquement pour 1-2 règles très locales ou
  des keyframes propres au composant.

## Pourquoi

- **Code-splitting automatique** : Vite/Rolldown tracent les
  `import "...css"` par chunk JS, donc le CSS part dans le même chunk que
  le composant. Un visiteur qui n'ouvre jamais l'éditeur ne télécharge
  jamais `editor.css`.
- **Suppression sûre** : le style disparaît avec son composant, au lieu de
  survivre orphelin dans un dossier central.
- Évite les `:deep()` agressifs pour styler du contenu injecté.

## Comment l'appliquer

1. Nouveau CSS de feature → le créer **à côté du SFC**, pas sous `css/`.
2. L'importer dans le `<script setup>` du SFC, en tête, séparé du JS par
   une ligne vide.
3. Vraiment global (quasi toutes les pages) → `css/base/` + import dans
   `app.css`.
4. Header comment dans le fichier CSS : quel composant, quelle classe
   racine pose le scope.

### Références réelles

| Section | Fichier(s) | Importé par |
|---|---|---|
| Sidemenu admin | `src/Core/assets/backend/sidemenu/sidemenu.css` | `AppSidemenu.vue` |
| Éditeur de blocs | `src/Core/assets/shared/components/editor/editor.css` + `blocks.css` | `AppBlockEditor.vue` |
