import { ref, nextTick } from "vue";
import { useSlashCommands } from "@notes/suite/markdown/composables/useSlashCommands.js";
import { useWikiLinkAutocomplete } from "@notes/suite/markdown/composables/useWikiLinkAutocomplete.js";
import { handleMarkdownShortcut } from "@notes/suite/markdown/composables/useMarkdownShortcuts.js";
import { navigateTableCell } from "@notes/suite/markdown/composables/tableNavigation.js";
import { useTriggerAutocomplete } from "@notes/suite/markdown/composables/useTriggerAutocomplete.js";
import {
    isPastedAddress,
    markBlock,
    moveLines,
} from "@notes/suite/markdown/composables/editorTextActions.js";
import {
    foldText,
    loadEmojiData,
    searchEmoji,
} from "@notes/suite/markdown/composables/noteEmoji.js";

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
 * @param {string} [deps.locale] - fr, en or es, for the emoji names.
 * @param {import('vue').Ref<string[]>} [deps.allTags] - the notebook's tags, for `#`.
 * @param {(url: string) => Promise<string|null>} [deps.fetchLinkTitle] - a pasted address's page title.
 * @param {(id: string) => void} [deps.onBlockLink] - a paragraph was named, its link is wanted.
 */
export function useNoteEditorTextarea({
    emitUpdate,
    t,
    flatNotes,
    untitledLabel,
    locale = "fr",
    allTags = null,
    fetchLinkTitle = null,
    onBlockLink = null,
}) {
    const textareaRef = ref(null);
    /**
     * The wiki popover's header search field.
     *
     * The caret stays in the textarea when the popover opens. It used to jump
     * here instead, which read as helpful and was not: typing `[[test` put `[[`
     * in the note and `test` in this box, and once the box was emptied again
     * Backspace had nothing left to delete, so the `[[` looked stuck. Nothing
     * announced the jump, so the only way to find out was to lose a word.
     *
     * The field is still focusable on purpose - clicking it works, and
     * `onSearchKeydown` / `onSearchBlur` drive it from there. It is a display of
     * the query the textarea is already parsing, not the place you type it.
     */
    const searchInputRef = ref(null);

    const slash = useSlashCommands({ t });
    const wiki = useWikiLinkAutocomplete(flatNotes);

    // `:fus` offers the rocket (09/10/2026): emoji by name, in the reader's
    // language and by GitHub's shortcodes.
    const emoji = useTriggerAutocomplete({
        trigger: ":",
        queryPattern: /^[\p{L}\p{N}_+-]+$/u,
        minLength: 2,
        suggest: async (query) =>
            searchEmoji(await loadEmojiData(locale), query, 8),
        insertFor: (item) => item.emoji,
    });

    // `#cli` offers the notebook's tags that hold it, and a new tag is just
    // typed through.
    const tag = useTriggerAutocomplete({
        trigger: "#",
        queryPattern: /^[\p{L}\p{N}_/-]+$/u,
        minLength: 1,
        suggest: (query) => {
            const wanted = foldText(query);

            return (allTags?.value ?? [])
                .filter(
                    (one) =>
                        foldText(one).includes(wanted) &&
                        foldText(one) !== wanted,
                )
                .slice(0, 8)
                .map((one) => ({ tag: one }));
        },
        insertFor: (item) => `#${item.tag} `,
    });

    const menus = [emoji, tag];

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
        for (const menu of menus) void menu.onInput(event);
    }

    async function selectTriggered(menu, item) {
        const textarea = textareaRef.value;
        if (!textarea) return;
        const { newContent, newCaret } = menu.apply(
            textarea,
            item,
            textarea.value,
        );
        emitUpdate(newContent);
        await nextTick();
        textarea.focus();
        textarea.setSelectionRange(newCaret, newCaret);
    }

    /**
     * An address pasted alone becomes a link (09/10/2026). Over selected
     * text, the text is the label; otherwise the address is, until the
     * page's title comes back and takes its place - if the link is still
     * there as it was written.
     */
    async function onPaste(event) {
        const textarea = textareaRef.value;
        const pasted = event.clipboardData?.getData("text/plain") ?? "";
        if (!textarea || !isPastedAddress(pasted)) return;
        // An image pasted with its address is handled by the image upload.
        if (
            [...(event.clipboardData?.items ?? [])].some((item) =>
                item.type.startsWith("image/"),
            )
        )
            return;

        event.preventDefault();
        const url = pasted.trim();
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const content = textarea.value;
        const selected = content.slice(start, end);
        const link = `[${selected || url}](${url})`;
        await applyShortcut({
            newContent: content.slice(0, start) + link + content.slice(end),
            cursorPos: start + link.length,
        });

        if (selected || !fetchLinkTitle) return;
        const title = await fetchLinkTitle(url);
        if (!title) return;

        const current = textareaRef.value?.value ?? "";
        const placeholder = `[${url}](${url})`;
        const at = current.indexOf(placeholder);
        if (-1 === at) return;
        const titled = `[${title.replace(/[[\]]/g, "")}](${url})`;
        emitUpdate(
            current.slice(0, at) +
                titled +
                current.slice(at + placeholder.length),
        );
    }

    async function selectCommand(command) {
        const textarea = textareaRef.value;
        if (!textarea) return;
        const { newContent, newCaret, newCaretEnd } = slash.applyCommand(
            textarea,
            command,
            textarea.value,
        );
        emitUpdate(newContent);
        await nextTick();
        textarea.focus();
        // A table selects its first header, so typing replaces it.
        textarea.setSelectionRange(newCaret, newCaretEnd ?? newCaret);
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
    async function applyShortcut({
        newContent,
        cursorPos: cursorPosition,
        cursorEnd,
    }) {
        emitUpdate(newContent);
        const textarea = textareaRef.value;
        if (!textarea) return;
        await nextTick();
        textarea.focus();
        textarea.setSelectionRange(cursorPosition, cursorEnd ?? cursorPosition);
    }

    /**
     * Public helper used by external composables (image upload, …) that
     * want to inject content + restore the caret without knowing how
     * the textarea is wired internally.
     */
    function applyInsert(newContent, caretPosition) {
        return applyShortcut({ newContent, cursorPos: caretPosition });
    }

    /**
     * Keydown is consumed in priority order: Ctrl/Cmd shortcuts first
     * (so Ctrl+B always wraps, even with a popover open), then the
     * floating menus' keyboard handlers. Anything left passes through
     * to the textarea unmolested.
     */
    function onKeydown(event) {
        const textarea = textareaRef.value;

        // Alt+↑ / Alt+↓ move the line, or the selected lines, as in Obsidian.
        if (
            textarea &&
            event.altKey &&
            !event.ctrlKey &&
            !event.metaKey &&
            ("ArrowUp" === event.key || "ArrowDown" === event.key)
        ) {
            const moved = moveLines(
                textarea.value,
                textarea.selectionStart,
                textarea.selectionEnd,
                "ArrowUp" === event.key ? -1 : 1,
            );
            event.preventDefault();
            if (moved) applyShortcut(moved);

            return;
        }

        // Cmd/Ctrl+Shift+B names the paragraph and asks for its link.
        if (
            textarea &&
            onBlockLink &&
            (event.ctrlKey || event.metaKey) &&
            event.shiftKey &&
            "b" === event.key.toLowerCase()
        ) {
            event.preventDefault();
            const marked = markBlock(textarea.value, textarea.selectionStart);
            if (!marked) return;
            const caret = textarea.selectionStart;
            if (marked.newContent !== textarea.value)
                applyShortcut({
                    newContent: marked.newContent,
                    cursorPos: caret,
                });
            onBlockLink(marked.id);

            return;
        }

        for (const menu of menus) {
            if (!menu.show.value) continue;
            const picked = menu.onKeydown(event);
            if (picked) selectTriggered(menu, picked);

            return;
        }

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
            return;
        }

        // Tab inside a Markdown table moves between cells; anywhere else it
        // keeps its usual meaning.
        if (
            textarea &&
            event.key === "Tab" &&
            !event.ctrlKey &&
            !event.metaKey &&
            !event.altKey
        ) {
            const move = navigateTableCell(
                textarea.value,
                textarea.selectionStart,
                event.shiftKey,
            );
            if (move) {
                event.preventDefault();
                applyShortcut(move);
            }
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
            for (const menu of menus) menu.close();
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
        // `:` emoji and `#` tags
        emojiMenu: emoji,
        tagMenu: tag,
        selectEmoji: (item) => selectTriggered(emoji, item),
        selectTag: (item) => selectTriggered(tag, item),
        // shared (textarea)
        onInput,
        onKeydown,
        onBlur,
        onPaste,
    };
}
