import { describe, it, expect } from "vitest";
import { ref } from "vue";
import { useNoteTagFilter } from "./useNoteTagFilter.js";

const notes = [
    { id: 1, title: "A", tags: ["work", "urgent"] },
    { id: 2, title: "B", tags: ["personal"] },
    { id: 3, title: "C", tags: ["work"] },
    { id: 4, title: "D", tags: [] },
    { id: 5, title: "E" }, // missing tags
];

describe("useNoteTagFilter", () => {
    it("aggregates unique tags sorted case-insensitively", () => {
        const notesRef = ref(notes);
        const { availableTags } = useNoteTagFilter(notesRef);

        expect(availableTags.value).toEqual(["personal", "urgent", "work"]);
    });

    it("ignores empty and whitespace-only tags", () => {
        const notesRef = ref([{ id: 1, tags: ["a", "", "   ", "b"] }]);
        const { availableTags } = useNoteTagFilter(notesRef);

        expect(availableTags.value).toEqual(["a", "b"]);
    });

    it("toggles a tag in and out of the selection", () => {
        const notesRef = ref(notes);
        const { selectedTags, toggleTag } = useNoteTagFilter(notesRef);

        toggleTag("work");
        expect(selectedTags.value).toEqual(["work"]);

        toggleTag("urgent");
        expect(selectedTags.value).toEqual(["work", "urgent"]);

        toggleTag("work");
        expect(selectedTags.value).toEqual(["urgent"]);
    });

    it("clears the entire selection", () => {
        const notesRef = ref(notes);
        const { selectedTags, toggleTag, clearTags } =
            useNoteTagFilter(notesRef);

        toggleTag("work");
        toggleTag("urgent");
        clearTags();

        expect(selectedTags.value).toEqual([]);
    });

    it("recomputes available tags when the notes list changes", () => {
        const notesRef = ref([{ id: 1, tags: ["a"] }]);
        const { availableTags } = useNoteTagFilter(notesRef);

        expect(availableTags.value).toEqual(["a"]);

        notesRef.value = [{ id: 2, tags: ["b", "c"] }];
        expect(availableTags.value).toEqual(["b", "c"]);
    });
});
