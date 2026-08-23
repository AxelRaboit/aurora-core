---
name: convention_testing
description: Convention de test Vue/JS - co-location obligatoire, structure, patterns par type de fichier, ce qu'on teste et ce qu'on skip.
metadata:
  type: feedback
---

## Règle

**Tests Vue/JS → co-localisés à côté du fichier source** (convention Vitest/Vue moderne).

C'est intentionnellement différent des tests PHP (centralisés dans `tests/`) - deux écosystèmes, deux conventions établies.

**Tout fichier `.test.js` vit à côté de son fichier source**. Jamais dans un dossier `tests/` centralisé.

```
AppButton.vue
AppButton.test.js     ← même dossier
useForm.js
useForm.test.js       ← même dossier
```

`src/Core/assets/tests/helpers/` est la seule exception : test utilities partagées (ex: `createTestI18n.js`).

## Structure d'un test

```js
import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";       // pour les composants Vue
import { MyUtil } from "./myUtil.js";           // pour les utils pures

describe("MyUtil", () => {
    it("fait X quand Y", () => {
        expect(MyUtil("input")).toBe("expected");
    });
});
```

## Patterns par type

### Composant Vue

```js
import { mount } from "@vue/test-utils";
import { createI18n } from "vue-i18n";           // si useI18n() dans le composant

const i18n = createI18n({ legacy: false, locale: "en", messages: { en: { ... } } });

const wrapper = mount(AppXxx, {
    props: { ... },
    slots: { default: "<span>Content</span>" },
    global: {
        plugins: [i18n],
        stubs: { Teleport: true },               // pour les modales/tooltips avec Teleport
    },
});

expect(wrapper.find("button").exists()).toBe(true);
expect(wrapper.find("button").classes()).toContain("bg-accent-600");
await wrapper.find("button").trigger("click");
expect(wrapper.emitted("close")).toBeTruthy();
```

### Composable Vue

```js
// Appeler directement, sans mount()
const { items, page } = useLocalPagination({ ... });
expect(items.value).toHaveLength(3);
```

Pour les composables avec état module-level (`useTheme`, `usePrivileges`) :
```js
vi.resetModules();
const { useTheme } = await import("./useTheme.js");
```

### Utilitaire pur (pas de Vue)

```js
import { deepMerge } from "./deepMerge.js";
expect(deepMerge({ a: 1 }, { b: 2 })).toEqual({ a: 1, b: 2 });
```

### Debounce / timers

```js
vi.useFakeTimers();
// ... trigger action
vi.runAllTimers();
await nextTick();
// ... assert
vi.useRealTimers();
```

## Ce qu'on teste (3-5 tests par fichier)

- Props principales et leurs effets visuels (classes CSS, présence d'éléments)
- États conditionnels (`v-if`, `v-show`, `disabled`)
- Émissions d'événements (`wrapper.emitted("update:modelValue")`)
- Cas limites (valeur vide, null, 0)

## Ce qu'on skip

- Composables HTTP (`useRequest`, `usePaginatedFetch`) - trop couplés à fetch/mocks complexes
- Composables browser (`useResizable`, `useKeyboardShortcut`) - APIs DOM difficiles à isoler
- Composants qui n'ont que des dépendances lourdes sans logique propre testable

## Stubs courants

| Besoin | Solution |
|---|---|
| Teleport (modales, tooltips) | `global: { stubs: { Teleport: true } }` |
| Composant tiers (VueDatePicker, Chart) | `global: { stubs: { VueDatePicker: true } }` |
| Module au niveau module | `vi.mock("@/path/to/module.js", () => ({ ... }))` |
| localStorage / matchMedia | `vi.stubGlobal("localStorage", { getItem: vi.fn() })` |

## Pourquoi

**Why:** Établi lors de l'audit `src/Core/assets/shared/` (2026-05-14). L'ancien dossier `src/Core/assets/tests/` centralisé a été migré vers la co-location pour cohérence.

**How to apply:** Quand tu crées ou modifies un composant/composable/util Vue, vérifie si un `.test.js` co-localisé existe. S'il n'existe pas, le créer. 3-5 tests ciblés suffisent.
