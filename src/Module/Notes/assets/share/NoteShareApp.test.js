import { afterEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

vi.mock("@/shared/composables/useMediaQuery.js", async () => {
    const { ref } = await import("vue");

    return { useMediaQuery: () => ({ matches: ref(false) }) };
});

import NoteShareApp from "./NoteShareApp.vue";

const mounted = [];

function render(props = {}) {
    const wrapper = mount(NoteShareApp, {
        props: {
            imagePrefix: "/i/",
            shareImagePath: "/s/__filename__",
            shareNotePath: "/n/__id__",
            noteId: 3,
            noteTitle: "Fiche",
            content:
                "# Fiche\n\n## Contexte\n\nUn texte de quelques mots.\n\n## Suite\n\nEncore.",
            ...props,
        },
        global: { plugins: [createTestI18n()] },
    });
    mounted.push(wrapper);

    return wrapper;
}

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

describe("NoteShareApp", () => {
    /**
     * A shared note showed its banner, title and text only; its tags, its
     * date and its length are part of it too (09/10/2026).
     */
    it("shows what else the note is made of, under the title", async () => {
        const wrapper = render({
            meta: {
                tags: ["client", "devis"],
                updatedAt: "2026-10-09T09:00:00+00:00",
            },
        });
        await flushPromises();

        const meta = wrapper.find("[data-share-meta]");
        expect(meta.exists()).toBe(true);
        expect(wrapper.find("[data-share-tags]").text()).toContain("client");
        expect(wrapper.find("[data-share-tags]").text()).toContain("devis");
        expect(wrapper.find("[data-share-updated]").text()).toContain(
            "notes.markdown.library.columns.updated",
        );
        expect(wrapper.find("[data-share-length]").exists()).toBe(true);
    });

    /** The public reader has its own bar: nothing is repeated there. */
    it("adds nothing where the page around already says it", async () => {
        const wrapper = render();
        await flushPromises();

        expect(wrapper.find("[data-share-meta]").exists()).toBe(false);
    });
});
