import { ref, computed } from "vue";

/**
 * Aggregate the unique tags present across the flat notes list and expose
 * a toggleable selection. The tree composable consumes `selectedTags` to
 * filter the rendered arbo (OR semantics: a note is kept if it carries at
 * least one selected tag, ancestors preserved).
 */
export function useNoteTagFilter(notesRef) {
    const selectedTags = ref([]);

    const availableTags = computed(() => {
        const set = new Set();
        for (const note of notesRef.value ?? []) {
            for (const tag of note.tags ?? []) {
                if (typeof tag === "string" && tag.trim() !== "") {
                    set.add(tag);
                }
            }
        }
        return Array.from(set).sort((a, b) =>
            a.localeCompare(b, undefined, { sensitivity: "base" }),
        );
    });

    function toggleTag(tag) {
        const index = selectedTags.value.indexOf(tag);
        if (index === -1) {
            selectedTags.value = [...selectedTags.value, tag];
        } else {
            selectedTags.value = selectedTags.value.filter((t) => t !== tag);
        }
    }

    function clearTags() {
        selectedTags.value = [];
    }

    /**
     * Drop selected filter tags that no longer exist in any note. Called
     * after a global tag operation (rename / merge / delete) so the
     * sidebar filter doesn't keep a now-orphaned tag selected.
     */
    function pruneMissingTags() {
        if (selectedTags.value.length === 0) return;
        const existing = new Set(
            (notesRef.value ?? []).flatMap((n) => n.tags ?? []),
        );
        selectedTags.value = selectedTags.value.filter((tag) =>
            existing.has(tag),
        );
    }

    return {
        availableTags,
        selectedTags,
        toggleTag,
        clearTags,
        pruneMissingTags,
    };
}
