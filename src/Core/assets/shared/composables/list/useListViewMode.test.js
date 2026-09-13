import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import { useListViewMode } from "./useListViewMode.js";

/**
 * A ResizeObserver that reports whatever width the test asks for.
 *
 * jsdom has none, and the composable treats its absence as "roomy" - which is
 * the right fallback and the wrong thing to assert against. Here the width is
 * the variable under test.
 */
let report;

function observedWidth(initial) {
    let handler = null;

    window.ResizeObserver = class {
        constructor(callback) {
            handler = callback;
        }

        observe() {
            handler([{ contentRect: { width: initial } }]);
        }

        disconnect() {}
    };

    report = (width) => handler([{ contentRect: { width } }]);
}

/** Binds the template ref the way a component would. */
async function mount(view, element = {}) {
    view.container.value = element;
    await nextTick();

    return view;
}

beforeEach(() => {
    Object.defineProperty(window, "location", {
        configurable: true,
        value: { ...window.location, search: "" },
    });
});

/**
 * The sidemenu is 480px fixed from 1024 up and hidden below, so a 768px window
 * leaves a list 718px and a 1024px window leaves it 478px. Every threshold
 * written against the window is therefore asking the wrong thing, and this is
 * what these tests pin down.
 */
describe("the list view mode", () => {
    it("keeps the chosen shape when the container has room", async () => {
        observedWidth(900);

        const { viewMode } = await mount(
            useListViewMode(["list", "grid"], "list"),
        );

        expect(viewMode.value).toBe("list");
    });

    it("falls back to cards when the container is narrow", async () => {
        // 478px: a 1024px window, where the sidemenu takes 480 of it.
        observedWidth(478);

        const { viewMode, storedViewMode } = await mount(
            useListViewMode(["list", "grid"], "list"),
        );

        expect(viewMode.value).toBe("grid");
        // The choice itself is untouched, so the link still says what it said.
        expect(storedViewMode.value).toBe("list");
    });

    it("gives the choice back when the container grows", async () => {
        observedWidth(478);

        const view = await mount(useListViewMode(["list", "grid"], "list"));

        expect(view.viewMode.value).toBe("grid");

        report(900);
        await nextTick();

        expect(view.isNarrow.value).toBe(false);
        expect(view.viewMode.value).toBe("list");
    });

    it("measures the container, not the window", async () => {
        // A wide window whose list sits in a narrow column is exactly the case
        // the back-office produces at 1024, and the one a media query misses.
        window.innerWidth = 1440;
        observedWidth(478);

        const { viewMode } = await mount(
            useListViewMode(["list", "grid"], "list"),
        );

        expect(viewMode.value).toBe("grid");
    });

    it("leaves a list alone when it has no cards to fall back to", async () => {
        observedWidth(300);

        const { viewMode } = await mount(
            useListViewMode(["list", "table"], "list"),
        );

        expect(viewMode.value).toBe("list");
    });

    it("assumes room until something is measured", () => {
        // No ResizeObserver at all: a server render, or a browser that predates
        // it. Flashing cards on every desktop load would be the worse guess.
        delete window.ResizeObserver;

        const { viewMode, isNarrow } = useListViewMode(
            ["list", "grid"],
            "list",
        );

        expect(isNarrow.value).toBe(false);
        expect(viewMode.value).toBe("list");
    });
});
