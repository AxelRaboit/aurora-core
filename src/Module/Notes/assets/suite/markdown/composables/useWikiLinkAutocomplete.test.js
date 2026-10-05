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
        const w = useWikiLinkAutocomplete(makeNotes());
        try {
            w.onInput(makeEvent("note about [[", 13));
        } catch {
            /* jsdom may not paint computed styles */
        }
        expect(w.showSuggestions.value).toBe(true);
    });

    it("filters suggestions by case-insensitive title substring", () => {
        const w = useWikiLinkAutocomplete(makeNotes());
        try {
            w.onInput(makeEvent("[[hello", 7));
        } catch {
            /* ignore */
        }
        const titles = w.filteredSuggestions.value.map((n) => n.title);
        expect(titles).toContain("Hello World");
        expect(titles).toContain("Hello Aurora");
        expect(titles).not.toContain("Project Plan");
    });

    it("caps suggestions at 8", () => {
        const many = ref(
            Array.from({ length: 20 }, (_, i) => ({
                id: i,
                title: `Note ${i}`,
            })),
        );
        const w = useWikiLinkAutocomplete(many);
        try {
            w.onInput(makeEvent("[[note", 6));
        } catch {
            /* ignore */
        }
        expect(w.filteredSuggestions.value.length).toBe(8);
    });

    it("closes when the user types ]] (caret past close)", () => {
        const w = useWikiLinkAutocomplete(makeNotes());
        try {
            w.onInput(makeEvent("[[hello]]", 9));
        } catch {
            /* ignore */
        }
        expect(w.showSuggestions.value).toBe(false);
    });

    it("does not open when a newline interrupts the bracket", () => {
        const w = useWikiLinkAutocomplete(makeNotes());
        try {
            w.onInput(
                makeEvent(
                    "[[oops\nstill typing",
                    "[[oops\nstill typing".length,
                ),
            );
        } catch {
            /* ignore */
        }
        expect(w.showSuggestions.value).toBe(false);
    });

    it("navigates with ArrowDown/ArrowUp", () => {
        const w = useWikiLinkAutocomplete(makeNotes());
        try {
            w.onInput(makeEvent("[[hello", 7));
        } catch {
            /* ignore */
        }
        w.onKeydown({ key: "ArrowDown", preventDefault: () => {} });
        expect(w.suggestionIndex.value).toBe(1);
        w.onKeydown({ key: "ArrowUp", preventDefault: () => {} });
        expect(w.suggestionIndex.value).toBe(0);
    });

    it("returns the picked note on Enter", () => {
        const w = useWikiLinkAutocomplete(makeNotes());
        try {
            w.onInput(makeEvent("[[hello", 7));
        } catch {
            /* ignore */
        }
        const picked = w.onKeydown({
            key: "Enter",
            preventDefault: () => {},
        });
        expect(picked?.title).toBe("Hello World");
    });

    it("closes on Escape", () => {
        const w = useWikiLinkAutocomplete(makeNotes());
        try {
            w.onInput(makeEvent("[[h", 3));
        } catch {
            /* ignore */
        }
        w.onKeydown({ key: "Escape", preventDefault: () => {} });
        expect(w.showSuggestions.value).toBe(false);
    });

    it("applySuggestion splices [[Title]] at the bracket position", () => {
        const w = useWikiLinkAutocomplete(makeNotes());
        const content = "see [[hel";
        try {
            w.onInput(makeEvent(content, content.length));
        } catch {
            /* ignore */
        }
        const picked = w.filteredSuggestions.value.find(
            (n) => n.title === "Hello World",
        );
        const textarea = { value: content, selectionStart: content.length };
        const { newContent, newCaret } = w.applySuggestion(
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
        const w = useWikiLinkAutocomplete(notes);
        try {
            w.onInput(makeEvent("[[", 2));
        } catch {
            /* ignore */
        }
        const textarea = { value: "[[", selectionStart: 2 };
        const { newContent } = w.applySuggestion(
            textarea,
            notes.value[0],
            "[[",
            "Untitled",
        );
        expect(newContent).toBe("[[Untitled]]");
    });
});
