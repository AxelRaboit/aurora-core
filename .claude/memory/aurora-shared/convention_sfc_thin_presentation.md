---
name: convention_sfc_thin_presentation
description: Les SFC Vue restent fines (templating + bindings). Toute logique métier (états réactifs orchestrés, watchers, computed non triviaux, helpers HTTP, transformations de données, lifecycle hooks autres que mount basic) va dans un composable co-localisé. Convention dure, rappelée par l'utilisateur le 2026-05-16.
metadata:
  type: feedback
---

## Règle

**Un SFC Vue (`.vue`) ne contient QUE de la présentation** : template, bindings,
imports de composants, refs UI-only (open/closed, hover, dragging), et la
glue minimale pour câbler des composables.

**Toute autre chose va dans un composable** `useXxx.js` co-localisé dans
`composables/` du même dossier feature :

| Type de logique | Doit aller dans un composable |
|---|---|
| État réactif orchestré (form + dirty + save + status) | ✅ |
| Watchers non triviaux (autosave, sync avec localStorage, debounce) | ✅ |
| Computed dérivés de plusieurs sources (sauf simples concat/lookup UI) | ✅ |
| Appels HTTP / interactions API | ✅ |
| Lifecycle hooks autres que mount/unmount cosmétiques | ✅ |
| Helpers de transformation (sort, filter, group) | ✅ |
| State machines (modes, étapes de wizard, statuts d'action) | ✅ |
| Timer / setTimeout / setInterval | ✅ |
| `useI18n` consommé pour des labels calculés | ✅ |
| Logique conditionnelle de routing / navigation | ✅ |

### Ce qui PEUT rester dans le SFC

- Refs purement UI : `const open = ref(false)`, `const hover = ref(null)`
- Computed très simples qui dérivent d'**un** ref pour le template (ex:
  `const initials = computed(() => name.value.slice(0, 2))`)
- Imports de composants enfants (`AppButton`, `AppTab`, …)
- Bindings `v-on:` qui appellent une méthode du composable
- Slots & teleports

## Pourquoi

**Why:** Aurora est un bundle distribué. Un client qui veut **réutiliser**
la logique d'une page (ex: appliquer le même flow autosave dans son
override) doit pouvoir importer le composable sans copier-coller la prose
template. Un SFC avec 200 lignes de logique métier dans `<script setup>`
n'est ni testable, ni partageable.

**How to apply:**

### 1. À l'écriture

Quand tu écris un nouveau `.vue`, dès qu'une de ces choses apparaît,
extrais-la dans un composable :
- ≥ 2 refs liés sémantiquement (form + savedSnapshot + saveStatus)
- Un watcher non trivial
- Un `setTimeout` / `setInterval`
- Plus de 3 fonctions async qui touchent à l'API
- Une state machine (modes, statuts)
- Toute logique qu'un autre composant pourrait raisonnablement vouloir réutiliser

### 2. Au refacto / à l'audit

```bash
# Repère les SFC trop gros - candidats à extraction
find assets -name "*.vue" -exec wc -l {} \; | sort -rn | head -20

# Repère les <script setup> trop volumineux dans un .vue donné
awk '/<script setup>/,/<\/script>/' fichier.vue | wc -l
```

Au-delà de **~80 lignes de `<script setup>`**, le SFC est probablement
trop chargé. Au-delà de **~120**, c'est certain.

### 3. Pattern d'extraction

```vue
<!-- ❌ Avant : tout dans le SFC -->
<script setup>
const form = ref({...});
const dirty = computed(() => ...);
const saveStatus = ref('idle');
let timer = null;
function scheduleSave() { ... }
async function save() { ... }
watch(form, () => scheduleSave(), { deep: true });
onBeforeUnmount(() => clearTimeout(timer));
</script>

<!-- ✅ Après : composable extrait -->
<script setup>
import { useEntityEditor } from './composables/useEntityEditor.js';
const { form, dirty, saveStatus, scheduleSave } = useEntityEditor({ api });
</script>
```

Le `.vue` ne contient plus que les refs UI, le câblage des composables,
et le template. Le composable est testable indépendamment (`vitest` sans
mount).

### 4. Co-location obligatoire

Le composable vit dans `composables/` à côté du SFC, **pas** dans
`@shared/composables` (sauf s'il est vraiment partagé entre ≥ 2
modules). Exemple :

```
src/Module/Notes/assets/backend/markdown/
  MarkdownNotesApp.vue
  components/
    NoteTagManagerModal.vue
  composables/
    useNotesEditor.js           ← métier du SFC principal
    useNoteTagManager.js        ← métier de la modale
    useMarkdownTagsApi.js       ← couche HTTP
```

Si plus tard un autre module veut réutiliser → **promote** vers
`src/Core/assets/shared/composables/` (voir `useAutoSave`, `useRelativeTime`).

## Anti-patterns observés

| Smell dans un `<script setup>` | Correctif |
|---|---|
| `const tagsApi = useMarkdownTagsApi(props); function onTagsChanged() { ... refresh + filter cleanup + reload selected }` | Pousse `onTagsChanged` dans `useNotesEditor` ou crée `useTagManagement` |
| Plusieurs `ref`/`watch` qui modélisent le même feature (renaming + deleting + selection) dans une modale | Crée `useNoteTagManager` qui orchestre la modale |
| `computed(() => { switch (status) { case 'saving': ... } })` avec labels traduits | Pousse dans un composable `useXxxStatusDisplay` ou exporte une fonction pure dans `utils/` |
| `useResizable` + `useViewMode` + `useNoteDragDrop` + ... câblés dans le SFC | Si > 5 composables câblés, regroupe sous un `useXxxLayout` qui les compose |

## À répéter à chaque session

L'utilisateur a rappelé cette règle explicitement le **2026-05-16** :
*"souvent quelques choses que tu oublies, faut bien noter quelque part
cette convention car c'est très important"*. Cette mémoire existe
spécifiquement pour qu'elle soit dans le contexte à **chaque nouvelle
session**. Le SFC fin n'est pas optionnel - c'est la convention de
référence pour tout `.vue` Aurora.
