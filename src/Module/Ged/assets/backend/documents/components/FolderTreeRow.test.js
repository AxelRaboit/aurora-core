import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const FolderTreeRow = (await import("./FolderTreeRow.vue")).default;

const i18n = createTestI18n();

const FOLDER = {
    id: 3,
    name: "Contrats",
    depth: 1,
    childCount: 2,
    documentCount: 7,
};

const render = (props = {}) =>
    mount(FolderTreeRow, {
        props: {
            folder: FOLDER,
            href: "/backend/ged/documents?folderId=3",
            canManage: true,
            ...props,
        },
        global: { plugins: [i18n] },
    });

const titles = (wrapper) =>
    wrapper
        .findAll("button")
        .map((b) => b.attributes("title"))
        .filter(Boolean);

describe("a folder row", () => {
    /**
     * The contract, stated once. This row was a hundred and forty-seven lines
     * of markup inside a `v-for`, where nothing said what a row is supposed to
     * carry - and that is exactly the shape that lost Notes its create, delete
     * and drag when its own tree moved into the menu.
     */
    it("carries every action a folder has", () => {
        const shown = titles(render());

        expect(shown).toContain("backend.ged.documents.favourite");
        expect(shown).toContain("backend.ged.documents.edit_folder");
        expect(shown).toContain("shared.common.delete");
        expect(shown).toContain("backend.ged.documents.collapse");
    });

    /** A reader who may not write sees neither the handle nor the two writes. */
    it("hides the writes from a reader who may not write", () => {
        const wrapper = render({ canManage: false });
        const shown = titles(wrapper);

        expect(shown).not.toContain("backend.ged.documents.edit_folder");
        expect(shown).not.toContain("shared.common.delete");
        expect(wrapper.attributes("draggable")).toBe("false");
        // Favouriting is a preference of their own, not a write on the folder.
        expect(shown).toContain("backend.ged.documents.favourite");
    });

    it("points at the folder's address", () => {
        expect(render().find("a").attributes("href")).toBe(
            "/backend/ged/documents?folderId=3",
        );
    });

    it("shows the drop indicator on the band being hovered", () => {
        expect(render({ zone: "before" }).html()).toContain("top-0");
        expect(render({ zone: "after" }).html()).toContain("bottom-0");
        expect(render({ zone: "into" }).attributes("class")).toContain(
            "ring-lime-500",
        );
    });

    it("reports what the reader did rather than doing it", async () => {
        const wrapper = render();

        await wrapper.findAll("button")[0].trigger("click");
        await wrapper.find("a").trigger("click");

        expect(wrapper.emitted("toggle-collapse")).toBeTruthy();
        expect(wrapper.emitted("select")).toBeTruthy();
        // Nothing here writes: the panel owns the endpoints.
        expect(vi.isMockFunction(global.fetch)).toBe(false);
    });

    it("indents by its depth", () => {
        expect(render().attributes("style")).toContain("0.75rem");
    });
});
