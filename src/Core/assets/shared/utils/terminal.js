/**
 * Le terminal qui tape tout seul - `[data-terminal]`.
 *
 * Tout le texte est dans la page dès le départ, lisible et copiable. Quand la
 * fenêtre arrive à l'écran, ce module l'efface et le retape : les commandes
 * caractère par caractère, les sorties d'un coup, comme dans un vrai
 * terminal. Rien ne bouge pour qui a demandé moins d'animations.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_terminal.html.twig
 */
const SELECTOR = "[data-terminal]";

/** Millisecondes par caractère tapé, et pause après une commande. */
const TYPE_MS = 28;
const PAUSE_MS = 350;

const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

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
