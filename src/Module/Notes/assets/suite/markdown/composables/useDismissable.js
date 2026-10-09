import { onBeforeUnmount, watch } from "vue";

/**
 * Closes a popover on a click beside it or on Escape (09/10/2026).
 *
 * @param {import('vue').Ref<HTMLElement|null>} elementRef  the popover, and whatever opened it inside
 * @param {import('vue').Ref<boolean>} open
 */
export function useDismissable(elementRef, open) {
    function onPointerDown(event) {
        if (elementRef.value && !elementRef.value.contains(event.target))
            open.value = false;
    }

    function onKeydown(event) {
        if ("Escape" === event.key) {
            event.stopPropagation();
            open.value = false;
        }
    }

    function stop() {
        document.removeEventListener("pointerdown", onPointerDown, true);
        document.removeEventListener("keydown", onKeydown, true);
    }

    watch(open, (isOpen) => {
        stop();
        if (isOpen) {
            document.addEventListener("pointerdown", onPointerDown, true);
            document.addEventListener("keydown", onKeydown, true);
        }
    });

    onBeforeUnmount(stop);
}
