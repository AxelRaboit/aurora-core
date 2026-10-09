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

document.addEventListener("DOMContentLoaded", initThemeToggle);
