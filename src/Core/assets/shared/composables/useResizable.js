import { onBeforeUnmount, ref, watch } from "vue";

/**
 * Pointer-driven resize for a panel (sidemenu, drawer, etc.).
 *
 * The composable owns the pixel size, persists it to localStorage, and exposes
 * a `startResize(event)` mousedown handler to bind on the drag handle. While
 * dragging, the cursor and user-select are locked on document.body so the
 * pointer doesn't flicker over hovered elements.
 *
 * @param {object} options
 * @param {string} options.key             localStorage key
 * @param {number} options.defaultValue    initial / reset value (px)
 * @param {number} [options.min=120]       minimum size (px)
 * @param {number} [options.max=600]       maximum size (px)
 * @param {"x"|"y"} [options.axis="x"]    drag axis
 * @param {(value: number) => void} [options.onChange] callback fired on each frame
 * @param {() => HTMLElement|null} [options.getOrigin] returns the element whose
 *        edge the drag is measured from (defaults to the handle's parent)
 */
export function useResizable({
    key,
    defaultValue,
    min = 120,
    max = 600,
    axis = "x",
    onChange,
    getOrigin = null,
} = {}) {
    const size = ref(loadStored());
    const dragging = ref(false);

    function loadStored() {
        try {
            const raw = localStorage.getItem(key);
            if (raw) {
                const parsed = parseInt(raw, 10);
                if (Number.isFinite(parsed)) return clamp(parsed);
            }
        } catch {
            // ignore (private mode, quota, etc.)
        }
        return defaultValue;
    }

    function clamp(value) {
        return Math.max(min, Math.min(max, Math.round(value)));
    }

    function persist() {
        try {
            localStorage.setItem(key, String(size.value));
        } catch {
            // ignore
        }
    }

    let originRect = null;

    function startResize(event) {
        if (event.button !== undefined && event.button !== 0) return;
        event.preventDefault();
        dragging.value = true;

        const handleElement = event.currentTarget;
        const origin = getOrigin ? getOrigin() : handleElement?.parentElement;
        originRect = origin?.getBoundingClientRect() ?? null;

        document.body.style.cursor = axis === "x" ? "col-resize" : "row-resize";
        document.body.style.userSelect = "none";

        document.addEventListener("pointermove", onMove);
        document.addEventListener("pointerup", onUp, { once: true });
    }

    function onMove(event) {
        if (!originRect) return;
        const next =
            axis === "x"
                ? event.clientX - originRect.left
                : event.clientY - originRect.top;
        size.value = clamp(next);
    }

    function onUp() {
        dragging.value = false;
        originRect = null;
        document.body.style.cursor = "";
        document.body.style.userSelect = "";
        document.removeEventListener("pointermove", onMove);
        persist();
    }

    function reset() {
        size.value = defaultValue;
        persist();
    }

    watch(size, (value) => onChange?.(value), { immediate: true });

    onBeforeUnmount(() => {
        document.removeEventListener("pointermove", onMove);
        document.body.style.cursor = "";
        document.body.style.userSelect = "";
    });

    return { size, dragging, startResize, reset };
}
