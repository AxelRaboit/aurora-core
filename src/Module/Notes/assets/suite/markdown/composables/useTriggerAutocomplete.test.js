import { describe, it, expect } from "vitest";
import { flushPromises } from "@vue/test-utils";
import { useTriggerAutocomplete } from "./useTriggerAutocomplete.js";

function textareaWith(value) {
    const textarea = document.createElement("textarea");
    document.body.append(textarea);
    textarea.value = value;
    textarea.setSelectionRange(value.length, value.length);

    return textarea;
}

function emojiMenu() {
    return useTriggerAutocomplete({
        trigger: ":",
        queryPattern: /^[a-z]+$/,
        minLength: 2,
        suggest: (query) => (query.startsWith("fu") ? [{ emoji: "🚀" }] : []),
        insertFor: (item) => item.emoji,
    });
}

describe("useTriggerAutocomplete", () => {
    it("opens at a word's start and replaces the typed name", async () => {
        const menu = emojiMenu();
        const textarea = textareaWith("Décollage :fu");
        await menu.onInput({ target: textarea });
        await flushPromises();

        expect(menu.show.value).toBe(true);
        expect(menu.apply(textarea, menu.items.value[0], textarea.value)).toEqual({ newContent: "Décollage 🚀", newCaret: 12 });
    });

    it("stays shut inside a word, before enough letters, and on a time", async () => {
        for (const value of ["mot:fu", "a :f", "à 10:30"]) {
            const menu = emojiMenu();
            await menu.onInput({ target: textareaWith(value) });
            await flushPromises();
            expect(menu.show.value).toBe(false);
        }
    });
});
