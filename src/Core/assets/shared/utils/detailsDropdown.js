/**
 * Disclosure dropdowns - `<details data-dropdown>`.
 *
 * The markup already works on its own: a <details> opens and closes from its
 * own <summary>, with no script. What native <details> does not do is the part
 * everyone expects from a dropdown - close when you click somewhere else, close
 * on Escape, and step aside when another one opens. This module adds only that,
 * so the page degrades to a still-usable switcher when the script never runs.
 *
 * Opt in from Twig by putting `data-dropdown` on the <details>.
 *
 * Markup: templates/Frontend/themes/default/partials/locale_switcher.html.twig
 */

const OPEN_SELECTOR = "details[data-dropdown][open]";

function closeAll(except) {
    document.querySelectorAll(OPEN_SELECTOR).forEach((dropdown) => {
        if (dropdown !== except) dropdown.open = false;
    });
}

// A click on a closed <summary> reaches here BEFORE the browser runs the toggle
// default action, so the dropdown being opened is not yet `[open]` and matches
// nothing - which is exactly right: every other one closes, then this one opens.
function onClick(event) {
    closeAll(event.target.closest?.(OPEN_SELECTOR) ?? null);
}

function onKeydown(event) {
    if (event.key !== "Escape") return;

    const open = document.querySelector(OPEN_SELECTOR);
    if (!open) return;

    closeAll();
    // Escape closed the panel from inside it - put the caller back on the
    // trigger rather than dropping focus to the top of the document.
    open.querySelector("summary")?.focus();
}

document.addEventListener("click", onClick);
document.addEventListener("keydown", onKeydown);
