/**
 * The strip of labels on a tabs zone - `<div data-tabs>`.
 *
 * The markup arrives as every panel open, each under its own heading, and that
 * is the feature rather than a placeholder: a reader without JavaScript gets
 * all the text instead of one panel and no way to the rest. What happens here
 * is an upgrade.
 *
 * The pattern is the one the WAI-ARIA practices describe, and it is written
 * out rather than pulled from a library because the whole of it is here: one
 * `tablist`, one visible panel, arrow keys that move between labels, and Home
 * and End for the ends. Roving `tabindex` so Tab steps past the strip rather
 * than through it - a reader who does not want the tabs should reach the page
 * in one press.
 *
 * Markup: templates/Frontend/themes/default/editorial/post/_grid_zone.html.twig
 */
const SELECTOR = "[data-tabs]";

function upgrade(root) {
    const panels = [...root.querySelectorAll("[data-tabs-panel]")];

    // One panel is not a choice, and a zone with none is not a zone.
    if (panels.length < 2) {
        return;
    }

    const strip = document.createElement("div");
    strip.setAttribute("role", "tablist");
    strip.className = "mb-4 flex flex-wrap gap-2 border-b border-line";

    const buttons = panels.map((panel, index) => {
        const heading = panel.querySelector("[data-tabs-label]");
        const button = document.createElement("button");

        button.type = "button";
        button.id = heading?.id ?? `tab-${index}`;
        button.setAttribute("role", "tab");
        button.setAttribute("aria-controls", panel.id);
        button.textContent = heading?.textContent ?? String(index + 1);
        button.className =
            "-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors";

        // The heading did its job in the markup; the label now carries the
        // name, and two of them would be the same words read twice.
        heading?.remove();

        strip.appendChild(button);

        return button;
    });

    function show(index) {
        buttons.forEach((button, i) => {
            const current = i === index;

            button.setAttribute("aria-selected", String(current));
            button.tabIndex = current ? 0 : -1;
            button.classList.toggle("border-accent", current);
            button.classList.toggle("text-accent", current);
            button.classList.toggle("border-transparent", !current);
            button.classList.toggle("text-secondary", !current);

            panels[i].hidden = !current;
        });
    }

    buttons.forEach((button, index) => {
        button.addEventListener("click", () => show(index));

        button.addEventListener("keydown", (event) => {
            const moves = {
                ArrowRight: index + 1,
                ArrowLeft: index - 1,
                Home: 0,
                End: buttons.length - 1,
            };

            const target = moves[event.key];

            if (undefined === target) {
                return;
            }

            event.preventDefault();

            // Wrapping, which is what the practices ask for: the row is a
            // loop, not a line with two dead ends.
            const next = (target + buttons.length) % buttons.length;

            show(next);
            buttons[next].focus();
        });
    });

    root.prepend(strip);
    show(0);
}

function arm() {
    document.querySelectorAll(SELECTOR).forEach(upgrade);
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
