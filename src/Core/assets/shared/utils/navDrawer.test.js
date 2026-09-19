import { describe, it, expect, beforeEach, vi } from "vitest";

const listeners = [];

// jsdom ne fournit pas `matchMedia` : le module l'appelle au chargement, donc
// le double doit être en place avant l'import.
vi.stubGlobal("matchMedia", (query) => ({
    matches: false,
    media: query,
    addEventListener: (_type, handler) => listeners.push(handler),
    removeEventListener: () => {},
}));

await import("./navDrawer.js");

function mount() {
    document.body.innerHTML = `
        <details data-nav-drawer data-dropdown id="drawer">
            <summary id="trigger">Menu</summary>
            <div data-nav-drawer-veil id="veil"></div>
            <nav>
                <a href="/contact" id="page">Contact</a>
                <a href="#tarifs" id="anchor">Tarifs</a>
            </nav>
        </details>
    `;

    return document.getElementById("drawer");
}

function click(element) {
    element.dispatchEvent(new MouseEvent("click", { bubbles: true }));
}

describe("navDrawer", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
    });

    it("closes on the veil, which swallows the outside click", () => {
        const drawer = mount();
        drawer.open = true;

        click(document.getElementById("veil"));

        expect(drawer.open).toBe(false);
        expect(document.activeElement).toBe(document.getElementById("trigger"));
    });

    it("closes on an anchor of the current page, which reloads nothing", () => {
        const drawer = mount();
        drawer.open = true;

        click(document.getElementById("anchor"));

        expect(drawer.open).toBe(false);
    });

    it("leaves a link to another page to the navigation", () => {
        const drawer = mount();
        drawer.open = true;

        click(document.getElementById("page"));

        expect(drawer.open).toBe(true);
    });

    it("closes when the viewport widens past the breakpoint", () => {
        const drawer = mount();
        drawer.open = true;

        listeners.forEach((handler) => handler({ matches: true }));

        expect(drawer.open).toBe(false);
    });

    it("stays put when the viewport narrows", () => {
        const drawer = mount();
        drawer.open = true;

        listeners.forEach((handler) => handler({ matches: false }));

        expect(drawer.open).toBe(true);
    });
});
