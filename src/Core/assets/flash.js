import { createApp, h, ref, onMounted, onBeforeUnmount } from "vue";
import { Toaster, toast } from "vue-sonner";
import "vue-sonner/style.css";
import { takeFlashes } from "@/shared/utils/flash.js";

// Reads the active app theme from the <html> class set by the theme script.
// `light` / `dark` switch between the two vue-sonner palettes; on the front
// (light bg) the toast stays light, on the admin (dark bg) it stays dark.
function detectTheme() {
    return document.documentElement.classList.contains("dark")
        ? "dark"
        : "light";
}

document.addEventListener("DOMContentLoaded", () => {
    const toasterContainer = document.createElement("div");
    document.body.appendChild(toasterContainer);
    createApp({
        setup() {
            const theme = ref(detectTheme());
            const observer = new MutationObserver(() => {
                theme.value = detectTheme();
            });
            onMounted(() =>
                observer.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ["class"],
                }),
            );
            onBeforeUnmount(() => observer.disconnect());
            return () =>
                h(Toaster, {
                    theme: theme.value,
                    position: "bottom-center",
                    richColors: true,
                });
        },
    }).mount(toasterContainer);

    const flashes = window.__flash__ ?? {};
    for (const [type, messages] of Object.entries(flashes)) {
        for (const message of messages) {
            (toast[type] ?? toast)(message);
        }
    }

    // What the previous page's own script queued before navigating away.
    for (const { type, message } of takeFlashes()) {
        toast[type](message);
    }
});
