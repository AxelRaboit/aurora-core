import { onBeforeUnmount, onMounted, ref } from "vue";

/**
 * The categorical palette, as colours a canvas can actually use.
 *
 * `AppShareBar` writes `var(--chart-cat-N)` straight into a style binding and
 * that works, because it renders elements and the browser resolves the property.
 * Chart.js paints into a canvas: handing it `var(--chart-cat-1)` is not a colour,
 * it is an unparseable string, and the line comes out in Chart.js's own default
 * rather than in the palette. So a canvas chart resolves the tokens first.
 *
 * Re-read when the theme changes, since the tokens move with it and a canvas has
 * no way to notice on its own. Same mechanism `AppChart` uses for its ink.
 *
 * @param {number} slots how many of the eight to resolve
 */
export function useChartPalette(slots = 8) {
    const colours = ref([]);
    let observer = null;

    function read() {
        const style = getComputedStyle(document.documentElement);

        colours.value = Array.from({ length: slots }, (_, index) =>
            style.getPropertyValue(`--chart-cat-${index + 1}`).trim(),
        );
    }

    /**
     * The same colour at a given opacity, for an area fill under its own line.
     * `color-mix` is resolved here rather than left in the string for the same
     * reason the tokens are: the canvas would not parse it either.
     */
    function withAlpha(index, alpha) {
        const colour = colours.value[index] ?? "";
        if (!colour.startsWith("#") || 7 !== colour.length) {
            return colour;
        }

        const channel = (at) => parseInt(colour.slice(at, at + 2), 16);

        return `rgba(${channel(1)}, ${channel(3)}, ${channel(5)}, ${alpha})`;
    }

    onMounted(() => {
        read();
        observer = new MutationObserver(read);
        observer.observe(document.documentElement, {
            attributeFilter: ["class"],
        });
    });

    onBeforeUnmount(() => observer?.disconnect());

    return { colours, withAlpha };
}
