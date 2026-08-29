import { ref, nextTick, watch } from "vue";
import { useSlashCommands } from "@notes/backend/markdown/composables/useSlashCommands.js";
import { useWikiLinkAutocomplete } from "@notes/backend/markdown/composables/useWikiLinkAutocomplete.js";
import { handleMarkdownShortcut } from "@notes/backend/markdown/composables/useMarkdownShortcuts.js";

/**
 * Wiring for the markdown notes textarea + its two floating menus:
 *   - slash-command palette (`/<query>` at line start)
 *   - wiki-link autocomplete (`[[<query>` anywhere on a line)
 *
 * Owns the textarea ref, forwards input updates to the parent via the
 * given `emitUpdate(value)` callback, and routes keyboard / input
 * events through both menus. The two are mutually exclusive - only
 * one ever opens because their trigger patterns can't overlap. On
 * selection (Enter / Tab / click) the picked snippet / `[[Title]]` is
 * spliced into the textarea and the caret is restored on the next tick.
 *
 * Stays a composable so the SFC can be pure presentation: bind
 * `textareaRef` + the returned event handlers + each menu's state and
 * the rest is mechanical.
 *
 * @param {object} deps
 * @param {(text: string) => void} deps.emitUpdate
 * @param {(key: string) => string} deps.t
 * @param {import('vue').Ref<Array<{id, title}>>} deps.flatNotes - the
 *   live note list, used by the wiki autocomplete to filter titles.
 * @param {string} [deps.untitledLabel] - i18n fallback when a note has
 *   no title.
 */
export function useNoteEditorTextarea({
    emitUpdate,
    t,
    flatNotes,
    untitledLabel,
}) {
    const textareaRef = ref(null);
    // The SFC binds `ref="searchInputRef"` on the wiki-popover header
    // search field. When the popover opens we move focus into it so the
    // user can keep typing without clicking the bar manually.
    const searchInputRef = ref(null);

    const slash = useSlashCommands({ t });
    const wiki = useWikiLinkAutocomplete(flatNotes);

    watch(wiki.showSuggestions, async (open) => {
        if (!open) return;
        await nextTick();
        searchInputRef.value?.focus();
    });

    /**
     * Route input through both menus. The slash handler closes itself
     * when the line no longer starts with `/`; same for wiki when the
     * caret leaves an unclosed `[[`. Calling both in sequence is fine
     * because they look at different patterns.
     */
    function onInput(event) {
        emitUpdate(event.target.value);
        slash.onInput(event);
        wiki.onInput(event);
    }

    async function selectCommand(command) {
        const textarea = textareaRef.value;
        if (!textarea) return;
        const { newContent, newCaret } = slash.applyCommand(
            textarea,
            command,
            textarea.value,
        );
        emitUpdate(newContent);
        await nextTick();
        textarea.focus();
        textarea.setSelectionRange(newCaret, newCaret);
    }

    async function selectSuggestion(note) {
        const textarea = textareaRef.value;
        if (!textarea) return;
        const { newContent, newCaret } = wiki.applySuggestion(
            textarea,
            note,
            textarea.value,
            untitledLabel ?? "",
        );
        emitUpdate(newContent);
        await nextTick();
        textarea.focus();
        textarea.setSelectionRange(newCaret, newCaret);
    }

    /**
     * Apply a markdown shortcut result to the textarea: emit the new
     * content, then restore the caret / selection on the next tick.
     * Used by Ctrl+B / Ctrl+I etc. via `handleMarkdownShortcut`.
     */
    async function applyShortcut({ newContent, cursorPos, cursorEnd }) {
        emitUpdate(newContent);
        const textarea = textareaRef.value;
        if (!textarea) return;
        await nextTick();
        textarea.focus();
        textarea.setSelectionRange(cursorPos, cursorEnd ?? cursorPos);
    }

    /**
     * Public helper used by external composables (image upload, …) that
     * want to inject content + restore the caret without knowing how
     * the textarea is wired internally.
     */
    function applyInsert(newContent, caretPos) {
        return applyShortcut({ newContent, cursorPos: caretPos });
    }

    /**
     * Keydown is consumed in priority order: Ctrl/Cmd shortcuts first
     * (so Ctrl+B always wraps, even with a popover open), then the
     * floating menus' keyboard handlers. Anything left passes through
     * to the textarea unmolested.
     */
    function onKeydown(event) {
        const textarea = textareaRef.value;
        if (textarea) {
            const shortcut = handleMarkdownShortcut(
                event,
                textarea,
                textarea.value,
            );
            if (shortcut) {
                applyShortcut(shortcut);
                return;
            }
        }

        if (slash.showSlash.value) {
            const picked = slash.onKeydown(event);
            if (picked) selectCommand(picked);
            return;
        }
        if (wiki.showSuggestions.value) {
            const picked = wiki.onKeydown(event);
            if (picked) selectSuggestion(picked);
        }
    }

    function onBlur() {
        // Defer so a mousedown on a menu item still fires before the
        // menu unmounts (click → mousedown ⇒ blur on textarea ⇒
        // immediate close would race the click).
        //
        // Skip the close when focus moved into an open floating menu
        // (typically the user clicked the wiki-search input). Otherwise
        // the popover would dismiss itself the instant the user tries
        // to interact with its own controls.
        setTimeout(() => {
            const next = document.activeElement;
            if (
                next &&
                typeof next.closest === "function" &&
                next.closest("[data-floating-menu]")
            ) {
                return;
            }
            slash.closeSlash();
            wiki.closeSuggestions();
        }, 150);
    }

    /**
     * Keydown handler attached to the wiki-search input so the user
     * can navigate / pick from the popover while it's focused. We
     * route through the wiki composable just like the textarea does,
     * minus the slash branch (slash never opens via the search input).
     */
    function onSearchKeydown(event) {
        if (!wiki.showSuggestions.value) return;
        const picked = wiki.onKeydown(event);
        if (picked) selectSuggestion(picked);
    }

    /**
     * Close the popover when the search input loses focus to anything
     * outside the menu (e.g. user clicks back on the textarea or tabs
     * away). The same deferred / inside-menu check the textarea uses.
     */
    function onSearchBlur() {
        setTimeout(() => {
            const next = document.activeElement;
            if (
                next &&
                typeof next.closest === "function" &&
                next.closest("[data-floating-menu]")
            ) {
                return;
            }
            wiki.closeSuggestions();
        }, 150);
    }

    return {
        textareaRef,
        searchInputRef,
        applyInsert,
        // slash palette
        showSlash: slash.showSlash,
        slashIndex: slash.slashIndex,
        slashPosition: slash.slashPosition,
        filteredCommands: slash.filteredCommands,
        selectCommand,
        highlightCommand: (index) => {
            slash.slashIndex.value = index;
        },
        // wiki autocomplete
        showSuggestions: wiki.showSuggestions,
        suggestionQuery: wiki.suggestionQuery,
        suggestionIndex: wiki.suggestionIndex,
        suggestionPosition: wiki.suggestionPosition,
        filteredSuggestions: wiki.filteredSuggestions,
        selectSuggestion,
        highlightSuggestion: wiki.highlightSuggestion,
        onSearchKeydown,
        onSearchBlur,
        // shared (textarea)
        onInput,
        onKeydown,
        onBlur,
    };
}
