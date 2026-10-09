const THEME_KEY = "aurora-theme";

function currentTheme() {
    return document.documentElement.classList.contains("dark")
        ? "dark"
        : "light";
}

function applyTheme(theme) {
    const htmlElement = document.documentElement;
    htmlElement.classList.add("theme-transitioning");
    htmlElement.classList.toggle("dark", theme === "dark");
    htmlElement.style.colorScheme = theme;
    localStorage.setItem(THEME_KEY, theme);
    window.setTimeout(
        () => htmlElement.classList.remove("theme-transitioning"),
        300,
    );
}

function initThemeToggle() {
    const button = document.getElementById("theme-toggle");
    if (!button) return;

    const iconMoon = button.querySelector(".icon-moon");
    const iconSun = button.querySelector(".icon-sun");

    function render() {
        const dark = currentTheme() === "dark";
        if (iconMoon) iconMoon.style.display = dark ? "none" : "";
        if (iconSun) iconSun.style.display = dark ? "" : "none";
        // The button is named after what it does: switch to the other
        // theme. The labels come with the button, already translated.
        const label = dark
            ? button.dataset.labelLight
            : button.dataset.labelDark;
        if (label) {
            button.setAttribute("aria-label", label);
            button.setAttribute("title", label);
        }
    }

    button.addEventListener("click", () => {
        applyTheme(currentTheme() === "dark" ? "light" : "dark");
        render();
    });

    render();
}

const WIDE_KEY = "aurora-page-wide";

/**
 * The column of a page that can take the whole window, and its button.
 *
 * The page marks the column `data-widenable`; the button flips `data-wide` on
 * it, shows the icon of what it would do and is named after it. The choice is
 * the reader's and stays in their browser: a guest has no account to keep it.
 */
function initWidthToggle() {
    const button = document.getElementById("width-toggle");
    const column = document.querySelector("[data-widenable]");
    if (!button || !column) return;

    const iconWiden = button.querySelector(".icon-widen");
    const iconNarrow = button.querySelector(".icon-narrow");

    function render(wide) {
        column.dataset.wide = wide ? "true" : "false";
        if (iconWiden) iconWiden.style.display = wide ? "none" : "";
        if (iconNarrow) iconNarrow.style.display = wide ? "" : "none";
        const label = wide
            ? button.dataset.labelNarrow
            : button.dataset.labelWiden;
        if (label) {
            button.setAttribute("aria-label", label);
            button.setAttribute("title", label);
        }
        button.setAttribute("aria-pressed", wide ? "true" : "false");
    }

    let stored = null;
    try {
        stored = localStorage.getItem(WIDE_KEY);
    } catch {
        // Storage refused (private window): the column simply starts narrow.
    }

    button.addEventListener("click", () => {
        const wide = "true" !== column.dataset.wide;
        render(wide);
        try {
            localStorage.setItem(WIDE_KEY, wide ? "1" : "0");
        } catch {
            // Kept for this visit only.
        }
    });

    render("1" === stored);
}

document.addEventListener("DOMContentLoaded", initThemeToggle);
document.addEventListener("DOMContentLoaded", initWidthToggle);
