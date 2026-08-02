# Aurora CSS — organisation & conventions

CSS organisé pour **refléter la structure de `src/`** (Vue/JS et PHP
co-localisés depuis 0.5). Chaque
fichier matche la couche/le module qu'il style, pour qu'on retrouve les
styles à côté de leur code logique.

## Structure

Deux emplacements, selon la portée :

**1. Global — `src/Core/assets/css/`**, importé par `app.css` :

```
src/Core/assets/css/
├── app.css                    # Entry — orchestre les imports GLOBAUX uniquement
├── email.css                  # Standalone, monté par les emails seulement
│
└── base/                      # Tout ce qui est chargé sur (presque) toutes les pages
    ├── theme.css              #   tokens de thème
    ├── theme-transition.css
    ├── base.css
    ├── scrollbar.css
    ├── content-blocks.css     #   styles du contenu rendu (blocs éditeur)
    ├── input.css              #   composants shared utilisés partout
    ├── loader.css
    └── modal.css
```

**2. Feature / module — co-localisé à côté du composant**, importé par le
SFC lui-même :

```
src/Core/assets/backend/sidemenu/
├── AppSidemenu.vue            # import "./sidemenu.css"
└── sidemenu.css

src/Core/assets/shared/components/editor/
├── AppBlockEditor.vue         # import "./editor.css" + "./blocks.css"
├── editor.css
└── blocks.css
```

> **Pourquoi co-localisé et pas un miroir `css/modules/<name>/`** : le CSS
> vit avec le composant qui le consomme, comme le reste de `src/` depuis
> 0.5. On le trouve sans chercher, il se supprime avec son composant, et
> un module packagé séparément emporte ses styles avec lui.

## Règle d'or — où importer ?

Vite/Rolldown trackent les `import "...css"` par chunk JS : **le CSS est
shipé dans le même chunk que la JS qui le consomme**. Donc :

- **Importer dans `app.css`** seulement si le CSS est **vraiment**
  utilisé sur toutes (ou presque) les pages : base, theme, scrollbar,
  composants shared utilisés partout (input, modal, loader).
- **Importer dans le SFC** dès qu'un CSS est lié à une feature/module
  précis : EditorJS host, sidebar admin, preview markdown, etc. Le
  navigateur ne télécharge le CSS que quand la page qui mount le SFC
  est chargée.

### Exemple — AppBlockEditor

```vue
<script setup>
import "./editor.css";   // ⬅ CSS d'abord, séparée d'une ligne vide
import "./blocks.css";

import EditorJS from "@editorjs/editorjs";
</script>
```

Si tu visites `/backend/dashboard`, `editor.css` n'est jamais téléchargé.
Tu ouvres un écran qui monte l'éditeur, il arrive avec le chunk de ce
composant.

### Ordre des imports dans un `.vue`

1. **CSS d'abord** (`import "./…"` ou `import "@/css/…"`), un par ligne.
2. **Ligne vide** comme séparateur.
3. **JS imports ensuite** (Vue, composables, components, utils).

Pourquoi : ça met les side-effects (le CSS est un side-effect import) en
tête de fichier, et ça matche l'ordre dans lequel le navigateur applique
les styles avant le rendu JS.

## Conventions

### 1. Où vivent les fichiers

| Type de style | Emplacement | Importé depuis |
|---|---|---|
| **Base / theme** (tokens, scrollbar, body) | `css/base/` | `app.css` |
| **Composant partagé chargé partout** (input, modal, loader) | `css/base/` | `app.css` |
| **Composant shared à portée limitée** | à côté du `.vue` | le SFC concerné |
| **Core admin** (`src/Core/assets/*`) | à côté du `.vue` | le SFC concerné |
| **Module** (`src/Module/<Name>/assets/*`) | à côté du `.vue` | le SFC concerné |

### 2. Inline `<style>` vs fichier externe vs Tailwind

**Préférer Tailwind via `:class`** pour 95% des cas (le design system y est).

**Préférer un fichier externe** dès qu'on a :
- Plus de ~5 règles cohérentes
- Du CSS qui cible du contenu **rendu** (`v-html`, EditorJS, marked, …) —
  les styles scopés Vue ne peuvent pas styler du HTML injecté sans
  `:deep()` partout, et le résultat est plus net dans un `.css` dédié.
- Des règles qui dépendent d'une classe parente fixée par le composant
  (`.note-preview`, `.prose-preview`, `.codex-editor`, …) — la portée
  est déjà naturelle, on peut sortir les rules d'un coup.

**Garder inline `<style scoped>`** uniquement pour :
- Quelques règles très locales à un SFC sans dépendance externe.
- Des animations / keyframes spécifiques au composant.

### 3. Naming d'un fichier de module

- 1 fichier par feature/preview cohérent : `markdown-preview.css`,
  `editor.css`, `blocks.css`, …
- Pas de fourre-tout `notes.css` qui mixerait plusieurs features.
  Préférer plusieurs petits fichiers.
- Toutes les règles **doivent être scopées** par une classe racine
  spécifique au module (`.note-preview`, `.editor-block-holder`,
  `.prose-preview`, …) pour éviter de polluer globalement.

### 4. Ajouter un nouveau fichier

1. Créer le fichier dans le bon dossier (`modules/<name>/<feature>.css`).
2. **L'importer depuis le SFC qui en a besoin** (`import "@/css/...";`
   en haut du `<script setup>`). Ne **pas** l'ajouter à `app.css` sauf
   si vraiment global.
3. Header comment expliquant **quel composant / feature** consomme ces
   styles, et **où** la classe racine est posée (cf.
   `markdown-preview.css` pour le template).

### 5. Variables CSS / tokens de couleur

Les tokens (`--th-primary`, `--th-surface`, `--color-accent-500`, …) sont
définis dans `base/theme.css`. **Toujours** passer par les tokens — pas
de hex en dur dans les feuilles modulaires, sauf pour des accents
type-spécifiques (cf. les `--callout-color` de markdown-preview).

### 6. `email.css`

Standalone — chargé indépendamment par `src/Core/templates/Shared/email/layout/
base.html.twig` via `inline_css()`. **Ne pas** l'importer dans
`app.css` ni inclure des selectors Tailwind compliqués (les clients
mail ne supportent que CSS inline limité).
