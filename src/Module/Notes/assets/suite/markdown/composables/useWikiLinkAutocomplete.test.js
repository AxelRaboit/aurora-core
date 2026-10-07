import { describe, it, expect } from "vitest";
import { ref } from "vue";
import { useWikiLinkAutocomplete } from "./useWikiLinkAutocomplete.js";

function makeNotes() {
    return ref([
        { id: 1, title: "Hello World" },
        { id: 2, title: "Project Plan" },
        { id: 3, title: "Hello Aurora" },
        { id: 4, title: "Random" },
    ]);
}

function makeEvent(text, caret) {
    return { target: { value: text, selectionStart: caret } };
}

describe("useWikiLinkAutocomplete", () => {
    it("opens when the caret sits inside an unclosed [[", () => {
        const autocomplete = useWikiLinkAutocomplete(makeNotes());
        try {
            autocomplete.onInput(makeEvent("note about [[", 13));
        } catch {
            /* jsdom may not paint computed styles */
        }
        expect(autocomplete.showSuggestions.value).toBe(true);
    });

    it("filters suggestions by case-insensitive title substring", () => {
        const autocomplete = useWikiLinkAutocomplete(makeNotes());
        try {
            autocomplete.onInput(makeEvent("[[hello", 7));
        } catch {
            /* ignore */
        }
        const titles = autocomplete.filteredSuggestions.value.map(
            (suggestion) => suggestion.title,
        );
        expect(titles).toContain("Hello World");
        expect(titles).toContain("Hello Aurora");
        expect(titles).not.toContain("Project Plan");
    });

    it("caps suggestions at 8", () => {
        const many = ref(
            Array.from({ length: 20 }, (_, index) => ({
                id: index,
                title: `Note ${index}`,
            })),
        );
        const autocomplete = useWikiLinkAutocomplete(many);
        try {
            autocomplete.onInput(makeEvent("[[note", 6));
        } catch {
            /* ignore */
        }
        expect(autocomplete.filteredSuggestions.value.length).toBe(8);
    });

    it("closes when the user types ]] (caret past close)", () => {
        const autocomplete = useWikiLinkAutocomplete(makeNotes());
        try {
            autocomplete.onInput(makeEvent("[[hello]]", 9));
        } catch {
            /* ignore */
        }
        expect(autocomplete.showSuggestions.value).toBe(false);
    });

    it("does not open when a newline interrupts the bracket", () => {
        const autocomplete = useWikiLinkAutocomplete(makeNotes());
        try {
            autocomplete.onInput(
                makeEvent(
                    "[[oops\nstill typing",
                    "[[oops\nstill typing".length,
                ),
            );
        } catch {
            /* ignore */
        }
        expect(autocomplete.showSuggestions.value).toBe(false);
    });

    it("navigates with ArrowDown/ArrowUp", () => {
        const autocomplete = useWikiLinkAutocomplete(makeNotes());
        try {
            autocomplete.onInput(makeEvent("[[hello", 7));
        } catch {
            /* ignore */
        }
        autocomplete.onKeydown({ key: "ArrowDown", preventDefault: () => {} });
        expect(autocomplete.suggestionIndex.value).toBe(1);
        autocomplete.onKeydown({ key: "ArrowUp", preventDefault: () => {} });
        expect(autocomplete.suggestionIndex.value).toBe(0);
    });

    it("returns the picked note on Enter", () => {
        const autocomplete = useWikiLinkAutocomplete(makeNotes());
        try {
            autocomplete.onInput(makeEvent("[[hello", 7));
        } catch {
            /* ignore */
        }
        const picked = autocomplete.onKeydown({
            key: "Enter",
            preventDefault: () => {},
        });
        expect(picked?.title).toBe("Hello World");
    });

    it("closes on Escape", () => {
        const autocomplete = useWikiLinkAutocomplete(makeNotes());
        try {
            autocomplete.onInput(makeEvent("[[h", 3));
        } catch {
            /* ignore */
        }
        autocomplete.onKeydown({ key: "Escape", preventDefault: () => {} });
        expect(autocomplete.showSuggestions.value).toBe(false);
    });

    it("applySuggestion splices [[Title]] at the bracket position", () => {
        const autocomplete = useWikiLinkAutocomplete(makeNotes());
        const content = "see [[hel";
        try {
            autocomplete.onInput(makeEvent(content, content.length));
        } catch {
            /* ignore */
        }
        const picked = autocomplete.filteredSuggestions.value.find(
            (suggestion) => suggestion.title === "Hello World",
        );
        const textarea = { value: content, selectionStart: content.length };
        const { newContent, newCaret } = autocomplete.applySuggestion(
            textarea,
            picked,
            content,
            "Untitled",
        );
        expect(newContent).toBe("see [[Hello World]]");
        expect(newCaret).toBe("see [[Hello World]]".length);
    });

    it("applySuggestion falls back to the untitled label for an empty title", () => {
        const notes = ref([{ id: 1, title: "" }]);
        const autocomplete = useWikiLinkAutocomplete(notes);
        try {
            autocomplete.onInput(makeEvent("[[", 2));
        } catch {
            /* ignore */
        }
        const textarea = { value: "[[", selectionStart: 2 };
        const { newContent } = autocomplete.applySuggestion(
            textarea,
            notes.value[0],
            "[[",
            "Untitled",
        );
        expect(newContent).toBe("[[Untitled]]");
    });
});
