import { afterEach, describe, it, expect } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import NoteQuickOpen from "./NoteQuickOpen.vue";
import { noteExcerpt } from "../composables/useWikiLinkHoverCard.js";

const NOTES = [
    { id: 1, title: "Cabinet Verrier", updatedAt: "2026-10-01T10:00:00Z" },
    { id: 2, title: "Sommaire des clients", updatedAt: "2026-10-08T10:00:00Z" },
    { id: 3, title: "Contrat type", updatedAt: "2026-10-05T10:00:00Z" },
];

const mounted = [];
afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

async function render() {
    const wrapper = mount(NoteQuickOpen, {
        props: { show: false, notes: NOTES },
        global: { plugins: [createTestI18n()] },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    await wrapper.setProps({ show: true });
    await flushPromises();

    return wrapper;
}

function titles() {
    return [...document.body.querySelectorAll("[data-note-quick-result]")].map(
        (button) => button.textContent.trim(),
    );
}

describe("NoteQuickOpen", () => {
    it("shows the recent notes first, before anything is typed", async () => {
        await render();
        expect(titles()).toEqual([
            "Sommaire des clients",
            "Contrat type",
            "Cabinet Verrier",
        ]);
    });

    it("finds a note by the words of its title, accents and case aside", async () => {
        const wrapper = await render();
        wrapper.vm.$.setupState.query = "verrIER";
        await flushPromises();

        expect(titles()).toEqual(["Cabinet Verrier"]);
        expect(
            document.body.querySelector("[data-note-quick-create]"),
        ).not.toBeNull();
    });

    it("opens the chosen note, and offers to create a missing one", async () => {
        const wrapper = await render();
        wrapper.vm.$.setupState.query = "Nouvelle idée";
        await flushPromises();
        document.body.querySelector("[data-note-quick-create]").click();

        expect(wrapper.emitted("create")[0]).toEqual(["Nouvelle idée"]);
    });
});

describe("noteExcerpt", () => {
    it("keeps the beginning of a long note", () => {
        const long = Array.from(
            { length: 30 },
            (_, index) => `ligne ${index}`,
        ).join("\n");
        expect(noteExcerpt(long).split("\n")).toHaveLength(12);
    });
});
