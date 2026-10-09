import { afterEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

// The date picker reads the theme, which asks `matchMedia` on import.
vi.mock("@/shared/composables/useTheme", () => ({
    useTheme: () => ({ theme: { value: "light" }, toggle: vi.fn() }),
}));

vi.mock("@notes/suite/markdown/composables/useNoteShareApi.js", () => ({
    useNoteShareApi: () => ({
        list: vi.fn().mockResolvedValue({ links: [] }),
        listPeople: vi.fn().mockResolvedValue({ members: [], people: [] }),
        preview: vi.fn().mockResolvedValue({ notes: [] }),
        create: vi.fn(),
        revoke: vi.fn(),
        setPerson: vi.fn(),
        removePerson: vi.fn(),
    }),
}));

import NoteShareModal from "./NoteShareModal.vue";

const mounted = [];

async function render() {
    const wrapper = mount(NoteShareModal, {
        props: { show: false, noteId: 12, paths: {} },
        global: { plugins: [createTestI18n()] },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    await wrapper.setProps({ show: true });
    await flushPromises();

    return wrapper;
}

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

function hint() {
    return document.body
        .querySelector("[data-share-by-link-hint]")
        .textContent.trim();
}

async function tick(selector) {
    const box = document.body.querySelector(`${selector} input[type=checkbox]`);
    box.click();
    await flushPromises();
}

describe("NoteShareModal", () => {
    /**
     * The sentence under "Par un lien" says what the link will let its holder
     * do: it said "En lecture seule" with writing ticked (09/10/2026).
     */
    it("says what the link will allow, boxes as they stand", async () => {
        await render();
        expect(hint()).toBe("notes.markdown.share.by_link_hint");

        await tick("[data-share-can-write]");
        expect(hint()).toBe("notes.markdown.share.by_link_hint_write");

        await tick("[data-share-coediting]");
        expect(hint()).toBe("notes.markdown.share.by_link_hint_live");

        // Unticking writing takes live co-editing along, and the sentence too.
        await tick("[data-share-can-write]");
        expect(hint()).toBe("notes.markdown.share.by_link_hint");
    });
});
