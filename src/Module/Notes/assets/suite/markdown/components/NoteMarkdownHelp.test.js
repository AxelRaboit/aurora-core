import { afterEach, describe, it, expect } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import NoteMarkdownHelp from "./NoteMarkdownHelp.vue";
import {
    NOTE_HELP_SECTIONS,
    filterHelp,
    localizeExample,
    shortcutLabel,
} from "../composables/noteMarkdownHelp.js";

const mounted = [];
afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

async function render(props = {}) {
    const wrapper = mount(NoteMarkdownHelp, {
        props: { show: true, ...props },
        global: { plugins: [createTestI18n()] },
        attachTo: document.body,
    });
    mounted.push(wrapper);
    await flushPromises();

    return wrapper;
}

describe("NoteMarkdownHelp", () => {
    it("lists every entry of every section", async () => {
        await render();
        const total = NOTE_HELP_SECTIONS.reduce(
            (count, section) => count + section.entries.length,
            0,
        );
        expect(
            document.body.querySelectorAll("[data-note-help-entry]").length,
        ).toBe(total);
    });

    it("inserts an example at the caret, then closes", async () => {
        const wrapper = await render();
        document.body.querySelector('[data-note-help-use="highlight"]').click();
        await flushPromises();

        expect(wrapper.emitted("insert")[0][0]).toEqual({
            text: "====",
            caret: 2,
        });
        expect(wrapper.emitted("close")).toBeTruthy();
    });
});

describe("noteMarkdownHelp", () => {
    it("finds entries by any word of their label, syntax or shortcut", () => {
        const found = filterHelp(
            NOTE_HELP_SECTIONS,
            "mermaid",
            (entry) => entry.key,
        );
        expect(
            found.flatMap((section) =>
                section.entries.map((entry) => entry.key),
            ),
        ).toEqual(["diagram"]);
    });

    it("writes shortcuts the way the keyboard does", () => {
        expect(shortcutLabel("Mod+Shift+B", true)).toBe("⌘+⇧+B");
        expect(shortcutLabel("Mod+Shift+B", false)).toBe("Ctrl+Shift+B");
    });

    it("puts the examples' words in the reader's language", () => {
        expect(localizeExample("{rouge}texte{/}", "en")).toBe("{red}text{/}");
        expect(localizeExample("{rouge}texte{/}", "fr")).toBe(
            "{rouge}texte{/}",
        );
    });
});
