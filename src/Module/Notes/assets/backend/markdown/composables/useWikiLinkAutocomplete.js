import { ref, computed } from "vue";
import { positionFloatingMenu } from "@notes/backend/markdown/composables/positionFloatingMenu.js";

/**
 * Wiki-link autocomplete for the markdown textarea.
 *
 * Detection: typing `[[` (anywhere on a line, not just line-start)
 * opens a dropdown listing the user's existing notes whose title
 * matches the chars typed after `[[`. Selecting a note (Enter / Tab /
 * click) splices `[[Title]]` into the textarea at the bracket position.
 *
 * Mirror-div positioning so the dropdown follows the caret. The
 * `flatNotes` ref is the same flat `{id, title, …}` list the sidebar
 * tree uses, so the autocomplete reflects the live note list (creating
 * or renaming a note updates suggestions on the next debounce save).
 *
 * Ported from Onyx (`resources/js/composables/notes/useNoteWikiLinks.js`,
 * function `useWikiLinkAutocomplete`).
 *
 * @param {import('vue').Ref<Array<{id, title}>>} flatNotes
 */
export function useWikiLinkAutocomplete(flatNotes) {
    const showSuggestions = ref(false);
    const suggestionQuery = ref("");
    const suggestionIndex = ref(0);
    const suggestionPosition = ref({ top: 0, left: 0 });
    // Offset in the textarea value where the `[[` starts. We replace
    // `[[query` (the substring from bracketStart to caret) when a
    // suggestion is picked.
    const bracketStart = ref(null);

    const filteredSuggestions = computed(() => {
        const query = suggestionQuery.value.toLowerCase();
        return flatNotes.value
            .filter((note) => (note.title ?? "").toLowerCase().includes(query))
            .slice(0, 8);
    });

    /**
     * Open the dropdown when the caret sits inside an unclosed `[[…`
     * with no newline between the brackets and the caret. Otherwise
     * close - including the case where the user typed `]]` and is now
     * past the closing brackets.
     */
    function onInput(event) {
        const textarea = event.target;
        const caret = textarea.selectionStart;
        const text = textarea.value;
        const before = text.slice(0, caret);

        const lastOpen = before.lastIndexOf("[[");
        const lastClose = before.lastIndexOf("]]");

        if (lastOpen !== -1 && lastOpen > lastClose) {
            const query = before.slice(lastOpen + 2);
            if (!query.includes("\n")) {
                bracketStart.value = lastOpen;
                suggestionQuery.value = query;
                suggestionIndex.value = 0;
                showSuggestions.value = true;
                positionDropdown(textarea, lastOpen);
                return;
            }
        }

        closeSuggestions();
    }

    function positionDropdown(textarea, startIndex) {
        suggestionPosition.value = positionFloatingMenu(textarea, startIndex);
    }

    /**
     * Returns the picked note on Enter/Tab so the caller can splice
     * `[[Title]]` into the textarea. Null otherwise.
     */
    function onKeydown(event) {
        if (!showSuggestions.value) return null;

        if (event.key === "ArrowDown") {
            event.preventDefault();
            suggestionIndex.value = Math.min(
                suggestionIndex.value + 1,
                filteredSuggestions.value.length - 1,
            );
        } else if (event.key === "ArrowUp") {
            event.preventDefault();
            suggestionIndex.value = Math.max(suggestionIndex.value - 1, 0);
        } else if (event.key === "Enter" || event.key === "Tab") {
            if (filteredSuggestions.value.length > 0) {
                event.preventDefault();
                return filteredSuggestions.value[suggestionIndex.value];
            }
        } else if (event.key === "Escape") {
            event.preventDefault();
            closeSuggestions();
        }
        return null;
    }

    /**
     * Replace the `[[query` substring (from bracketStart to caret)
     * with `[[Title]]`. Returns the new content + caret offset (placed
     * right after `]]` so the user can keep typing).
     */
    function applySuggestion(textarea, note, content, untitledLabel) {
        const start = bracketStart.value;
        const caret = textarea.selectionStart;
        const title = note.title || untitledLabel;
        const before = content.slice(0, start);
        const after = content.slice(caret);
        const insertion = `[[${title}]]`;
        const newContent = before + insertion + after;
        const newCaret = start + insertion.length;

        closeSuggestions();

        return { newContent, newCaret };
    }

    function closeSuggestions() {
        showSuggestions.value = false;
        suggestionQuery.value = "";
        suggestionIndex.value = 0;
        bracketStart.value = null;
    }

    function highlightSuggestion(index) {
        suggestionIndex.value = index;
    }

    return {
        showSuggestions,
        suggestionQuery,
        suggestionIndex,
        suggestionPosition,
        filteredSuggestions,
        onInput,
        onKeydown,
        applySuggestion,
        closeSuggestions,
        highlightSuggestion,
    };
}
