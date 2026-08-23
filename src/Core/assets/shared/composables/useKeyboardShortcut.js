import { onBeforeUnmount, onMounted } from "vue";

/**
 * Bind a global keyboard shortcut while the component is mounted.
 *
 * Example:
 *   useKeyboardShortcut({ key: "s", ctrl: true }, () => save());
 *   useKeyboardShortcut({ key: "k", ctrl: true }, () => openPalette());
 *
 * `ctrl: true` matches both Control (Win/Linux) and Cmd (macOS).
 * The handler is called with the original event; preventDefault() is called
 * automatically before invocation.
 *
 * A shortcut without a modifier does not fire while the reader is typing. That is
 * a rule rather than an option, and it follows from what the two kinds are for: a
 * bare letter is a command to the screen, so `m` must not switch to the month view
 * while somebody writes "mardi" in a title; a modifier combination is a command to
 * the application, and Ctrl+S has to save whatever has focus.
 */
/**
 * Whether keystrokes are going into something the reader is writing in.
 *
 * `isContentEditable` covers rich text editors, which are not inputs and are
 * exactly where a stray shortcut does the most damage.
 */
function isTyping(target) {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName)
    );
}

export function useKeyboardShortcut(
    { key, ctrl = false, target = null } = {},
    handler,
) {
    function onKeydown(event) {
        if (event.key.toLowerCase() !== key.toLowerCase()) return;
        if (ctrl && !(event.ctrlKey || event.metaKey)) return;
        if (!ctrl && (event.ctrlKey || event.metaKey)) return;
        if (!ctrl && isTyping(event.target)) return;
        event.preventDefault();
        handler(event);
    }

    onMounted(() => (target ?? window).addEventListener("keydown", onKeydown));
    onBeforeUnmount(() =>
        (target ?? window).removeEventListener("keydown", onKeydown),
    );
}
