import { nextTick, onMounted, ref, watch } from "vue";

/**
 * Shrinks a slide's type until it stops falling out of the slide.
 *
 * **The frame is 16:9 and cannot grow, so something has to give**, and until
 * now it was the text: a column centred inside a box it overflows escapes at
 * *both* ends, so a bullets slide with one bullet too many lost its title off
 * the top and its last line off the bottom, silently, with `overflow: hidden`
 * doing the cutting. A preview that hides what it cannot fit is exactly the
 * preview this module refused to build.
 *
 * The type scales through one custom property. Every size in the frame is
 * expressed in `cqw` - a fraction of the slide's own width - so multiplying
 * them all by the same factor is the same decision at 160px and at 1600px, and
 * a factor found on a thumbnail is still right on the wall.
 *
 * A ladder rather than a binary search: seven steps cover the useful range,
 * each step is one forced reflow, and a slide that still does not fit at the
 * bottom of the ladder is a slide with too many words on it - which the safe
 * centring then keeps readable from the top rather than from the middle.
 */
const LADDER = [1, 0.92, 0.84, 0.76, 0.68, 0.6, 0.54];

export function useSlideFit(watched) {
    const stage = ref(null);
    const fit = ref(1);

    function measure() {
        const element = stage.value;

        if (!element) return;

        for (const step of LADDER) {
            fit.value = step;
            // Written straight onto the node rather than through a binding: the
            // next read has to see this value, and a reactive style lands after
            // the render tick, one step too late every time.
            element.style.setProperty("--fit", String(step));

            if (element.scrollHeight <= element.clientHeight + 1) return;
        }
    }

    /**
     * Measured on mount, synchronously.
     *
     * The print page waits one frame and calls `window.print()`. A fit that
     * resolved on a later tick would print the slide at the size it did not
     * fit at, which is the one place nobody can correct it afterwards.
     */
    onMounted(measure);

    watch(watched, () => nextTick(measure), { deep: true });

    return { stage, fit, measure };
}
