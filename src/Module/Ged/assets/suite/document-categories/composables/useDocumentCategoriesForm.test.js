import { describe, it, expect, vi } from "vitest";
import { defineComponent, h } from "vue";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { useDocumentCategoriesForm } from "./useDocumentCategoriesForm.js";

vi.mock("vue-sonner", () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

/**
 * The field a client project would add: it is the one that revealed the bug,
 * since `Object.entries` runs on its list.
 */
const EXTRA = {
    color: { default: "#10b981", fromEntity: (cat) => cat.color ?? "" },
};

function run(extraFields = {}) {
    let api;

    const Comp = defineComponent({
        setup() {
            api = useDocumentCategoriesForm(
                "/create",
                "/update",
                "/delete",
                () => {},
                extraFields,
            );

            return () => h("div");
        },
    });

    mount(Comp, { global: { plugins: [createTestI18n({}, "fr")] } });

    return api;
}

describe("useDocumentCategoriesForm", () => {
    it("ouvre la fenêtre de création", () => {
        const { showCreate, openCreate } = run();

        openCreate();

        expect(showCreate.value).toBe(true);
    });

    // The bug: `openCreate` called `emptyForm()` without its fields, so
    // `Object.entries(undefined)` threw, the click handler died before
    // `showCreate`, and the "Ajouter une catégorie" button did nothing at
    // all - without showing anything, since Vue swallows the error.
    it("ouvre la fenêtre même quand un projet client a ajouté des champs", () => {
        const { showCreate, newCategory, openCreate } = run(EXTRA);

        openCreate();

        expect(showCreate.value).toBe(true);
        expect(newCategory.value.color).toBe("#10b981");
    });

    it("repart d'un formulaire vide à chaque ouverture", () => {
        const { newCategory, openCreate } = run(EXTRA);

        openCreate();
        newCategory.value.name = "Contrats";
        newCategory.value.color = "#ef4444";
        openCreate();

        expect(newCategory.value.name).toBe("");
        expect(newCategory.value.color).toBe("#10b981");
    });
});
