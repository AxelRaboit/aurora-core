import { onBeforeUnmount, onMounted, ref } from "vue";

/**
 * The current time, re-read every minute.
 *
 * A ref rather than a computed over `new Date()`: a computed has no reason to
 * re-evaluate as time passes, so the now line would freeze wherever the page
 * happened to load.
 *
 * One minute and not one second, because the grid positions the line to the
 * minute - a faster tick would redraw the same pixel row sixty times.
 */
export function useMinuteTicker() {
    const now = ref(new Date());

    let ticker = null;

    onMounted(() => {
        ticker = window.setInterval(() => {
            now.value = new Date();
        }, 60_000);
    });

    onBeforeUnmount(() => {
        if (null !== ticker) {
            window.clearInterval(ticker);
        }
    });

    return { now };
}
