import { describe, expect, it, vi } from "vitest";
import { defineComponent } from "vue";
import { mount } from "@vue/test-utils";
import { usePlanningShortcuts } from "./usePlanningShortcuts.js";

function mountShortcuts(busy = false) {
    const calls = {
        setView: vi.fn(),
        go: vi.fn(),
        goToToday: vi.fn(),
        createEvent: vi.fn(),
        createReminder: vi.fn(),
    };

    const wrapper = mount(
        defineComponent({
            setup() {
                usePlanningShortcuts({ isBusy: () => busy, ...calls });

                return () => null;
            },
        }),
        { attachTo: document.body },
    );

    return { wrapper, calls };
}

function press(key) {
    document.body.dispatchEvent(
        new KeyboardEvent("keydown", { key, bubbles: true }),
    );
}

describe("usePlanningShortcuts", () => {
    it("switches view on d, w and m", () => {
        const { wrapper, calls } = mountShortcuts();

        press("d");
        press("w");
        press("m");

        expect(calls.setView.mock.calls).toEqual([
            ["day"],
            ["week"],
            ["month"],
        ]);
        wrapper.unmount();
    });

    it("pages on n and p", () => {
        const { wrapper, calls } = mountShortcuts();

        press("n");
        press("p");

        expect(calls.go.mock.calls).toEqual([[1], [-1]]);
        wrapper.unmount();
    });

    /**
     * `j` and `k` alongside `n` and `p`, because Google binds both and the muscle
     * memory splits by which pair you learned.
     */
    it("also pages on j and k", () => {
        const { wrapper, calls } = mountShortcuts();

        press("j");
        press("k");

        expect(calls.go.mock.calls).toEqual([[1], [-1]]);
        wrapper.unmount();
    });

    it("goes to today on t", () => {
        const { wrapper, calls } = mountShortcuts();

        press("t");

        expect(calls.goToToday).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it("creates on c and r", () => {
        const { wrapper, calls } = mountShortcuts();

        press("c");
        press("r");

        expect(calls.createEvent).toHaveBeenCalledTimes(1);
        expect(calls.createReminder).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    /**
     * Nothing fires while something is open in front of the grid.
     *
     * Escape is what closes a modal, and a stray `d` while somebody reads an event
     * would move the grid out from under them.
     */
    it("stays quiet while a modal is open", () => {
        const { wrapper, calls } = mountShortcuts(true);

        for (const key of ["d", "w", "m", "t", "n", "p", "j", "k", "c", "r"]) {
            press(key);
        }

        for (const call of Object.values(calls)) {
            expect(call).not.toHaveBeenCalled();
        }

        wrapper.unmount();
    });
});
