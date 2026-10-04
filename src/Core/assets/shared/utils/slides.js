/**
 * A document turned slide by slide - `[data-slides]`.
 *
 * The server has already cut it at each section; this shows one slide at a
 * time and moves between them: the arrows below, the keyboard (arrows, page
 * keys, space), a swipe, and the address (`#diapo-3`), so a slide can be
 * sent and the back button steps back. Full screen on demand.
 *
 * Without it - no script, a crawler, a print - every slide is there, one
 * after the other, which is still the whole document.
 *
 * Gabarit : src/Module/Studio/templates/public/deliverable.html.twig
 */
const SELECTOR = "[data-slides]";

/** The slide a hash names, 0-based, or 0. */
export function slideFromHash(hash, count) {
    const match = /^#diapo-(\d+)$/.exec(hash ?? "");
    if (!match) return 0;

    return Math.min(count - 1, Math.max(0, Number(match[1]) - 1));
}

/**
 * The version made to be saved as PDF: every slide stays, one per printed
 * page (the stylesheet's `@media print`), and the print dialog opens once
 * the pictures are in - printing before them leaves grey boxes.
 */
function printDeck(deck) {
    deck.querySelectorAll(".aurora-reveal-armed").forEach((element) => {
        element.classList.remove("aurora-reveal-armed");
        element.classList.add("aurora-revealed");
    });
    const pictures = Array.from(deck.querySelectorAll("img")).map((image) => {
        image.loading = "eager";

        if (image.complete) return Promise.resolve();

        return new Promise((resolve) => {
            image.addEventListener("load", resolve, { once: true });
            image.addEventListener("error", resolve, { once: true });
        });
    });
    Promise.all(pictures).then(() =>
        window.setTimeout(() => window.print(), 300),
    );
}

function wire(deck) {
    if (deck.hasAttribute("data-slides-print")) {
        printDeck(deck);

        return;
    }

    const slides = Array.from(deck.querySelectorAll("[data-slide]"));
    const controls = deck.querySelector("[data-slides-controls]");
    const counter = deck.querySelector("[data-slides-counter]");
    if (slides.length < 2 || !controls) return;

    let current = slideFromHash(window.location.hash, slides.length);

    function show(index, { push = true } = {}) {
        current = Math.min(slides.length - 1, Math.max(0, index));
        slides.forEach((slide, position) => {
            slide.hidden = position !== current;
        });
        if (counter) counter.textContent = `${current + 1} / ${slides.length}`;
        deck.querySelector("[data-slides-prev]")?.toggleAttribute(
            "disabled",
            0 === current,
        );
        deck.querySelector("[data-slides-next]")?.toggleAttribute(
            "disabled",
            slides.length - 1 === current,
        );
        if (push)
            window.history.replaceState(null, "", `#diapo-${current + 1}`);
        // Each slide starts at its top, whatever the last one was scrolled to.
        if (!document.fullscreenElement) {
            window.scrollTo({
                top: Math.max(
                    0,
                    deck.getBoundingClientRect().top + window.scrollY - 16,
                ),
                behavior: "instant",
            });
        } else {
            deck.scrollTop = 0;
        }
        // A zone armed by scrollReveal waits to be scrolled to; a slide that
        // appears in place is never scrolled, so its zones arrive now.
        slides[current]
            .querySelectorAll(".aurora-reveal-armed")
            .forEach((element) => {
                element.classList.remove("aurora-reveal-armed");
                element.classList.add("aurora-revealed");
            });
    }

    deck.classList.add("is-presenting");
    controls.hidden = false;
    show(current, { push: false });

    deck.querySelector("[data-slides-prev]")?.addEventListener("click", () =>
        show(current - 1),
    );
    deck.querySelector("[data-slides-next]")?.addEventListener("click", () =>
        show(current + 1),
    );
    deck.querySelector("[data-slides-fullscreen]")?.addEventListener(
        "click",
        () => {
            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            } else {
                deck.requestFullscreen?.();
            }
        },
    );

    document.addEventListener("keydown", (event) => {
        if (
            event.target.closest?.("input, textarea, select, [contenteditable]")
        )
            return;
        if (["ArrowRight", "PageDown", " "].includes(event.key)) {
            event.preventDefault();
            show(current + 1);
        } else if (["ArrowLeft", "PageUp"].includes(event.key)) {
            event.preventDefault();
            show(current - 1);
        }
    });

    let startX = null;
    deck.addEventListener("pointerdown", (event) => {
        if ("touch" === event.pointerType) startX = event.clientX;
    });
    deck.addEventListener("pointerup", (event) => {
        if (null === startX) return;
        const delta = event.clientX - startX;
        startX = null;
        if (Math.abs(delta) > 60) show(current + (delta < 0 ? 1 : -1));
    });

    window.addEventListener("hashchange", () =>
        show(slideFromHash(window.location.hash, slides.length), {
            push: false,
        }),
    );
}

function init() {
    document.querySelectorAll(SELECTOR).forEach(wire);
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}
