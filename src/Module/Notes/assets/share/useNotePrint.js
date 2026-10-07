/**
 * A note on paper, or as a PDF: the browser's print.
 *
 * As for presentations, **the browser's print is the export**: "Save as PDF"
 * is in the same dialog, and the browser that drew the note prints the note
 * it drew, callouts, tables and images included. dompdf, which serves the
 * contracts, knows neither flex nor grid: a note's rendering would have to be
 * redone a second time, and the two kept in agreement.
 *
 * Two things the stylesheet cannot do alone:
 *
 * - **The dark theme does not print.** The browser drops backgrounds when
 *   printing and keeps the inks: almost white text on white paper. So the
 *   light theme is set for the duration of the print, on Ctrl+P as on the
 *   button, and the person's theme comes back afterwards.
 * - **A deferred image may not be loaded.** `loading="lazy"` waits for the
 *   page to be scrolled: printed straight away, the note came out with empty
 *   frames. The images all load before the dialog.
 */

/**
 * The light theme while printing, the person's theme afterwards.
 *
 * @returns {() => void} removes the listeners
 */
export function lightWhilePrinting() {
    const root = document.documentElement;
    let wasDark = false;

    const before = () => {
        wasDark = root.classList.contains("dark");
        if (wasDark) root.classList.remove("dark");
        eagerImages(document);
    };
    const after = () => {
        if (wasDark) root.classList.add("dark");
        wasDark = false;
    };

    window.addEventListener("beforeprint", before);
    window.addEventListener("afterprint", after);

    return () => {
        window.removeEventListener("beforeprint", before);
        window.removeEventListener("afterprint", after);
    };
}

function eagerImages(root) {
    const images = [...root.querySelectorAll("img")];
    images.forEach((image) => {
        if ("lazy" === image.getAttribute("loading"))
            image.setAttribute("loading", "eager");
    });

    return images;
}

/**
 * The print dialog, once every image has loaded or failed: a broken image
 * must not block printing the rest.
 *
 * @param {ParentNode} root
 * @param {number} timeout beyond it, print anyway
 */
export async function printWhenReady(root = document, timeout = 5000) {
    const pending = eagerImages(root)
        .filter((image) => !image.complete)
        .map(
            (image) =>
                new Promise((resolve) => {
                    image.addEventListener("load", resolve, { once: true });
                    image.addEventListener("error", resolve, { once: true });
                }),
        );

    await Promise.race([
        Promise.all(pending),
        new Promise((resolve) => setTimeout(resolve, timeout)),
    ]);

    // After the paint, otherwise the first page comes out empty. `print`
    // blocks until the dialog closes: nothing follows it.
    requestAnimationFrame(() => window.print());
}
