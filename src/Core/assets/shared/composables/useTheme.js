import { ref, watch } from "vue";

const STORAGE_KEY = "aurora-theme";

/**
 * The reader's own choice, or light.
 *
 * Light by default rather than the computer's setting: the suite opens in
 * its light theme, and the button in the top bar is how anyone moves to dark
 * (Axel, 10/10/2026). Must agree with `theme_script.html.twig`, which paints
 * the first frame before this runs.
 */
function getInitial() {
    let stored = null;
    try {
        stored = localStorage.getItem(STORAGE_KEY);
    } catch {
        // Storage refused (private window): the light theme stands.
    }
    return "dark" === stored ? "dark" : "light";
}

function apply(newTheme) {
    const htmlElement = document.documentElement;
    htmlElement.classList.add("theme-transitioning");
    htmlElement.classList.toggle("dark", newTheme === "dark");
    window.setTimeout(
        () => htmlElement.classList.remove("theme-transitioning"),
        300,
    );
}

const theme = ref(getInitial());
apply(theme.value);

export function useTheme() {
    watch(theme, (newTheme) => {
        apply(newTheme);
        localStorage.setItem(STORAGE_KEY, newTheme);
    });

    function toggle() {
        theme.value = theme.value === "dark" ? "light" : "dark";
    }

    return { theme, toggle };
}
