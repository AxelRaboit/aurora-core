import { ref, shallowRef } from "vue";
import { positionFloatingMenu } from "@notes/suite/markdown/composables/positionFloatingMenu.js";

/**
 * A menu that opens on a character typed at the start of a word and offers
 * what may follow it (09/10/2026): `:` for an emoji, `#` for a tag, `@` for a
 * person. One composable for the three, built like the `[[` one.
 *
 * The trigger counts only at a word's start - after a space, a line break or
 * at the very beginning - so a time (`10:30`), a heading (`# Title`, where a
 * space follows) or an address (`me@site.fr`) never opens anything.
 *
 * @param {object} options
 * @param {string} options.trigger           the opening character
 * @param {RegExp} options.queryPattern      what may follow it while the menu stays open
 * @param {number} [options.minLength]       characters needed before suggesting
 * @param {(query: string) => Promise<Array>|Array} options.suggest
 * @param {(item: object) => string} options.insertFor   the text that replaces `trigger + query`
 */
export function useTriggerAutocomplete({
    trigger,
    queryPattern,
    minLength = 1,
    suggest,
    insertFor,
}) {
    const show = ref(false);
    const query = ref("");
    const index = ref(0);
    const position = ref({ top: 0, left: 0 });
    const items = shallowRef([]);
    let start = null;
    let asked = 0;

    async function onInput(event) {
        const textarea = event.target;
        const caret = textarea.selectionStart;
        const before = textarea.value.slice(0, caret);
        const at = before.lastIndexOf(trigger);
        if (-1 === at) {
            close();

            return;
        }

        const previous = 0 === at ? "" : before[at - 1];
        const typed = before.slice(at + 1);
        if (
            !("" === previous || /\s/.test(previous)) ||
            !queryPattern.test(typed) ||
            typed.length < minLength
        ) {
            close();

            return;
        }

        start = at;
        query.value = typed;
        position.value = positionFloatingMenu(textarea, at);

        // Answers come back out of order when suggesting is asynchronous: only
        // the last question's answer is shown.
        asked += 1;
        const question = asked;
        const found = await suggest(typed);
        if (question !== asked || null === start) return;

        items.value = found;
        index.value = 0;
        show.value = found.length > 0;
    }

    /** The item picked on Enter or Tab, or null; arrows move, Escape closes. */
    function onKeydown(event) {
        if (!show.value) return null;

        if ("ArrowDown" === event.key) {
            event.preventDefault();
            index.value = Math.min(index.value + 1, items.value.length - 1);
        } else if ("ArrowUp" === event.key) {
            event.preventDefault();
            index.value = Math.max(index.value - 1, 0);
        } else if ("Enter" === event.key || "Tab" === event.key) {
            if (items.value.length > 0) {
                event.preventDefault();

                return items.value[index.value];
            }
        } else if ("Escape" === event.key) {
            event.preventDefault();
            close();
        }

        return null;
    }

    function apply(textarea, item, content) {
        const caret = textarea.selectionStart;
        const insertion = insertFor(item);
        const newContent =
            content.slice(0, start) + insertion + content.slice(caret);
        const newCaret = start + insertion.length;
        close();

        return { newContent, newCaret };
    }

    function close() {
        asked += 1;
        show.value = false;
        query.value = "";
        index.value = 0;
        items.value = [];
        start = null;
    }

    function highlight(value) {
        index.value = value;
    }

    return {
        show,
        query,
        index,
        position,
        items,
        onInput,
        onKeydown,
        apply,
        close,
        highlight,
    };
}
