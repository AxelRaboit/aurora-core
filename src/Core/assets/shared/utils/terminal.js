/**
 * The terminal that types by itself - `[data-terminal]`.
 *
 * All the text is in the page from the start, readable and copyable. When
 * the window comes on screen, this module erases it and types it again: the
 * commands character by character, the outputs all at once, as in a real
 * terminal. Nothing moves for whoever asked for fewer animations.
 *
 * Template: templates/Frontend/themes/default/editorial/post/zones/_terminal.html.twig
 */
const SELECTOR = "[data-terminal]";

/** Milliseconds per typed character, and pause after a command. */
const TYPE_MS = 28;
const PAUSE_MS = 350;

const wait = (delayMs) =>
    new Promise((resolve) => window.setTimeout(resolve, delayMs));

async function play(terminal) {
    const lines = [
        ...terminal.querySelectorAll(
            "[data-terminal-command], [data-terminal-output]",
        ),
    ];
    const texts = lines.map(
        (line) => line.querySelector("[data-terminal-type]")?.textContent ?? "",
    );

    lines.forEach((line) => {
        line.hidden = true;
    });

    for (const [index, line] of lines.entries()) {
        line.hidden = false;
        const typed = line.querySelector("[data-terminal-type]");

        if (!typed) {
            await wait(60);
            continue;
        }

        typed.textContent = "";
        for (const char of texts[index]) {
            typed.textContent += char;
            await wait(TYPE_MS);
        }
        await wait(PAUSE_MS);
    }
}

function arm() {
    const terminals = document.querySelectorAll(SELECTOR);

    if (
        0 === terminals.length ||
        window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ||
        !("IntersectionObserver" in window)
    ) {
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    void play(entry.target);
                }
            }
        },
        { threshold: 0.4 },
    );

    terminals.forEach((terminal) => observer.observe(terminal));
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
