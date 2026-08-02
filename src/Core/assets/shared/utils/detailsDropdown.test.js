import { describe, it, expect, beforeEach } from "vitest";
import "./detailsDropdown.js";

function mount(count = 2) {
    document.body.innerHTML = Array.from(
        { length: count },
        (_, index) => `
            <details data-dropdown id="d${index}">
                <summary>Trigger ${index}</summary>
                <div><a href="/x" id="link${index}">Link ${index}</a></div>
            </details>
        `,
    ).join("");

    return Array.from(document.querySelectorAll("details"));
}

function click(element) {
    element.dispatchEvent(new MouseEvent("click", { bubbles: true }));
}

function pressEscape() {
    document.dispatchEvent(
        new KeyboardEvent("keydown", { key: "Escape", bubbles: true }),
    );
}

describe("detailsDropdown", () => {
    beforeEach(() => {
        document.body.innerHTML = "";
    });

    it("closes an open dropdown on an outside click", () => {
        const [dropdown] = mount(1);
        dropdown.open = true;

        click(document.body);

        expect(dropdown.open).toBe(false);
    });

    it("leaves a dropdown open when the click lands inside it", () => {
        const [dropdown] = mount(1);
        dropdown.open = true;

        click(document.getElementById("link0"));

        expect(dropdown.open).toBe(true);
    });

    it("closes the others when a click opens one", () => {
        const [first, second] = mount(2);
        first.open = true;

        // The browser toggles `open` after the click event, so at listener time
        // the second one is still closed — same shape as clicking its summary.
        click(second.querySelector("summary"));

        expect(first.open).toBe(false);
    });

    it("closes on Escape and returns focus to the summary", () => {
        const [dropdown] = mount(1);
        dropdown.open = true;

        pressEscape();

        expect(dropdown.open).toBe(false);
        expect(document.activeElement).toBe(dropdown.querySelector("summary"));
    });

    it("ignores dropdowns that did not opt in", () => {
        document.body.innerHTML = `
            <details id="plain"><summary>Plain</summary></details>
        `;
        const plain = document.getElementById("plain");
        plain.open = true;

        click(document.body);

        expect(plain.open).toBe(true);
    });
});
