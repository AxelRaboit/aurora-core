import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h } from "vue";
import { mount } from "@vue/test-utils";
import {
    CLOSE_DELAY_MS,
    OPEN_DELAY_MS,
    PEEK_CLASS,
    useSidemenuPeek,
} from "./useSidemenuPeek.js";
import { SIDEMENU_COLLAPSE_EVENT } from "./useSidemenuCollapse.js";

function peek() {
    let api;
    const wrapper = mount(
        defineComponent({
            setup() {
                api = useSidemenuPeek();

                return () => h("div");
            },
        }),
    );

    return { api, wrapper };
}

const root = document.documentElement;
const peeking = () => root.classList.contains(PEEK_CLASS);

beforeEach(() => {
    vi.useFakeTimers();
    root.classList.add("sidemenu-collapsed");
});

afterEach(() => {
    vi.useRealTimers();
    root.classList.remove("sidemenu-collapsed", PEEK_CLASS);
});

describe("the folded menu, called back by the screen's edge", () => {
    it("comes once the pointer rests on the edge", () => {
        const { api } = peek();

        api.onEdgeEnter();
        expect(peeking(), "not on first contact").toBe(false);

        vi.advanceTimersByTime(OPEN_DELAY_MS);
        expect(peeking()).toBe(true);
    });

    it("ignores a pointer only passing over the edge", () => {
        const { api } = peek();

        api.onEdgeEnter();
        api.onEdgeLeave();
        vi.advanceTimersByTime(OPEN_DELAY_MS * 2);

        expect(peeking()).toBe(false);
    });

    it("goes when the pointer leaves the menu, unless it comes back in time", () => {
        const { api } = peek();
        api.onEdgeEnter();
        vi.advanceTimersByTime(OPEN_DELAY_MS);

        api.onMenuLeave();
        api.onMenuEnter();
        vi.advanceTimersByTime(CLOSE_DELAY_MS * 2);
        expect(peeking(), "came back in time").toBe(true);

        api.onMenuLeave();
        vi.advanceTimersByTime(CLOSE_DELAY_MS);
        expect(peeking()).toBe(false);
    });

    it("does nothing while the menu is unfolded", () => {
        root.classList.remove("sidemenu-collapsed");
        const { api } = peek();

        api.onEdgeEnter();
        vi.advanceTimersByTime(OPEN_DELAY_MS);

        expect(peeking()).toBe(false);
    });

    it("ends with Escape, and when the menu is folded or unfolded for good", () => {
        const { api } = peek();
        api.onEdgeEnter();
        vi.advanceTimersByTime(OPEN_DELAY_MS);

        window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        expect(peeking()).toBe(false);

        api.onEdgeEnter();
        vi.advanceTimersByTime(OPEN_DELAY_MS);
        window.dispatchEvent(
            new CustomEvent(SIDEMENU_COLLAPSE_EVENT, {
                detail: { collapsed: false },
            }),
        );
        expect(peeking()).toBe(false);
    });
});
