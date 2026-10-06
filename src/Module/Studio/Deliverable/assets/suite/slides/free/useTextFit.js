import { nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";

/**
 * Shrinks a text box's type until its words stay inside the box.
 *
 * **The free slide's answer to `useSlideFit`.** A laid-out slide fits its
 * whole stage, because the layout decides where everything goes; on a free
 * slide the person drew the box, so the box is the limit and only its own
 * words are shrunk. A box that grows instead would push into whatever was
 * drawn under it, which is the one thing a placed slide must not do.
 *
 * A bisection rather than a ladder: a box can be anything from a caption to a
 * poster headline, and seven fixed steps either stop too early or jump past
 * the size that fits. Eight rounds pin it to under half a per cent.
 *
 * The factor is written straight onto the node before each read, for the
 * reason `useSlideFit` gives: a reactive style lands a tick too late.
 */
export function useTextFit(enabled, watched) {
    const box = ref(null);
    const fit = ref(1);

    const fits = (element) =>
        element.scrollHeight <= element.clientHeight + 1 &&
        element.scrollWidth <= element.clientWidth + 1;

    function measure() {
        const element = box.value;

        if (!element) return;

        const apply = (value) =>
            element.style.setProperty("--fit", String(value));

        if (!enabled()) {
            fit.value = 1;
            apply(1);

            return;
        }

        apply(1);

        if (fits(element)) {
            fit.value = 1;

            return;
        }

        let low = 0.08;
        let high = 1;

        for (let round = 0; round < 8; round += 1) {
            const middle = (low + high) / 2;

            apply(middle);

            if (fits(element)) low = middle;
            else high = middle;
        }

        fit.value = low;
        apply(low);
    }

    /**
     * Measured again when a web font arrives: the first measure ran on the
     * fallback face, whose letters are another width, and a headline that fit
     * in Georgia overflows in Playfair.
     */
    const onFonts = () => measure();

    /**
     * And when the box itself changes size: a handle dragged, or the same
     * slide drawn larger when the player goes full screen.
     */
    let observer = null;

    onMounted(() => {
        measure();
        document.fonts?.addEventListener?.("loadingdone", onFonts);

        if (typeof ResizeObserver !== "undefined" && box.value) {
            observer = new ResizeObserver(() => measure());
            observer.observe(box.value);
        }
    });

    onBeforeUnmount(() => {
        document.fonts?.removeEventListener?.("loadingdone", onFonts);
        observer?.disconnect();
    });

    watch(watched, () => nextTick(measure), { deep: true });

    return { box, fit, measure };
}
