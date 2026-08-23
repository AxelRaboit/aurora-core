# Tests Vue / JS - Guide

## Convention : co-location

**Tests Vue/JS → co-localisés à côté du fichier source.**

Tout fichier `.test.js` vit **à côté de son fichier source**, dans le même dossier.

C'est intentionnellement différent des tests PHP (centralisés dans `tests/` - voir [testing_php.md](testing_php.md)). Deux écosystèmes, deux conventions établies.

```
src/Core/assets/shared/components/form/
  AppInput.vue
  AppInput.test.js       ✅ co-localisé

src/Module/Ged/assets/backend/documents/composables/
  useDocumentsForm.js
  useDocumentsForm.test.js  ✅ co-localisé
```

**Jamais** dans un dossier `tests/` centralisé.  
Exception : `src/Core/assets/tests/helpers/` - utilitaires de test partagés (ex: `createTestI18n.js`).

---

## Stack

- **Vitest** - runner de tests
- **@vue/test-utils** - monte les composants Vue en mémoire
- **happy-dom** - environnement DOM léger (défaut), ou jsdom si nécessaire

Lancer tous les tests :
```bash
npm run test          # watch mode
npx vitest run        # one-shot depuis la racine
```

Lancer un sous-ensemble :
```bash
npx vitest run src/Core/assets/shared/components/form
npx vitest run src/Module/Ged/assets
```

---

## Écrire un test de composant Vue

```js
import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import AppInput from "./AppInput.vue";

describe("AppInput", () => {
    it("affiche le placeholder", () => {
        const wrapper = mount(AppInput, {
            props: { placeholder: "Votre nom" },
        });
        expect(wrapper.find("input").attributes("placeholder")).toBe("Votre nom");
    });

    it("affiche le message d'erreur", () => {
        const wrapper = mount(AppInput, {
            props: { error: "Champ requis" },
        });
        expect(wrapper.text()).toContain("Champ requis");
    });

    it("émet update:modelValue à la saisie", async () => {
        const wrapper = mount(AppInput);
        await wrapper.find("input").setValue("hello");
        expect(wrapper.emitted("update:modelValue")?.[0]).toEqual(["hello"]);
    });
});
```

### Avec i18n

Si le composant appelle `useI18n()` :

```js
import { createI18n } from "vue-i18n";

const i18n = createI18n({
    legacy: false,
    locale: "en",
    messages: { en: { shared: { common: { save: "Save" } } } },
});

const wrapper = mount(AppXxx, {
    global: { plugins: [i18n] },
});
```

### Composants avec Teleport (modales, tooltips)

```js
const wrapper = mount(AppModal, {
    props: { show: true },
    global: { stubs: { Teleport: true } },  // rend le contenu inline
});
expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
```

### Stubs de composants tiers

```js
const wrapper = mount(AppDatePicker, {
    global: { stubs: { VueDatePicker: true } },
});
```

---

## Écrire un test de composable

Appeler le composable directement, sans `mount()` :

```js
import { useLocalPagination } from "./useLocalPagination.js";

describe("useLocalPagination", () => {
    it("retourne la première page", () => {
        const items = Array.from({ length: 25 }, (_, i) => i);
        const { page, pageItems } = useLocalPagination(items, { perPage: 10 });
        expect(page.value).toBe(1);
        expect(pageItems.value).toHaveLength(10);
    });
});
```

### Composables avec état module-level

Certains composables (`useTheme`, `usePrivileges`) initialisent leur état à l'import du module.
Pour les tester avec des états différents, réinitialiser le module entre chaque test :

```js
import { vi } from "vitest";

async function freshUseTheme(storedTheme = null) {
    vi.stubGlobal("localStorage", { getItem: vi.fn().mockReturnValue(storedTheme), setItem: vi.fn() });
    vi.resetModules();  // vide le cache de modules
    const { useTheme } = await import("./useTheme.js");
    return useTheme;
}
```

### Debounce et timers

```js
import { vi } from "vitest";
import { nextTick } from "vue";

it("déclenche après le délai", async () => {
    vi.useFakeTimers();
    // ... déclencher l'action
    vi.runAllTimers();
    await nextTick();
    // ... assert
    vi.useRealTimers();
});
```

---

## Écrire un test d'utilitaire pur

```js
import { describe, it, expect } from "vitest";
import { deepMerge } from "./deepMerge.js";

describe("deepMerge", () => {
    it("fusionne deux objets à plat", () => {
        expect(deepMerge({ a: 1 }, { b: 2 })).toEqual({ a: 1, b: 2 });
    });

    it("les clés de droite écrasent celles de gauche", () => {
        expect(deepMerge({ a: 1 }, { a: 99 })).toEqual({ a: 99 });
    });
});
```

---

## Ce qu'on teste (3-5 tests par fichier)

- **Props** : effets visuels (classes CSS, présence d'éléments DOM)
- **États** : conditionnels (`v-if`, `v-show`, `disabled`)
- **Événements** : `wrapper.emitted("update:modelValue")`
- **Cas limites** : valeur vide, null, 0, tableau vide

## Ce qu'on skip

| Type | Raison |
|---|---|
| Composables HTTP (`useRequest`, etc.) | Trop couplés à `fetch` - valeur faible vs complexité du mock |
| Composables browser (`useResizable`, `useKeyboardShortcut`) | APIs DOM difficiles à isoler proprement |
| Fichiers d'enum/constantes sans logique | Rien à tester |

---

## Référence rapide

| Besoin | Code |
|---|---|
| Trouver un élément | `wrapper.find("button")`, `wrapper.find('[role="dialog"]')` |
| Vérifier classes | `wrapper.find("div").classes().toContain("bg-accent-600")` |
| Vérifier texte | `wrapper.text()`, `wrapper.find("h2").text()` |
| Déclencher événement | `await wrapper.find("button").trigger("click")` |
| Saisir dans un input | `await wrapper.find("input").setValue("hello")` |
| Vérifier émission | `wrapper.emitted("close")` |
| Stub Teleport | `global: { stubs: { Teleport: true } }` |
| Mock module | `vi.mock("@/path.js", () => ({ fn: vi.fn() }))` |
| Stub global | `vi.stubGlobal("localStorage", { ... })` |
| Reset modules | `vi.resetModules()` + `await import(...)` |
