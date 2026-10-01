import { describe, it, expect, beforeEach, vi } from "vitest";
import { defineComponent, h, ref, nextTick } from "vue";
import { mount } from "@vue/test-utils";
import {
    useBackButtonClose,
    overlaysSettled,
    __resetBackButtonClose,
} from "./useBackButtonClose.js";

/**
 * A history stack that behaves like the browser's: `back()` is asynchronous,
 * and that asynchrony is the whole bug. A fake where `back()` popped
 * immediately would pass against the version that shipped.
 */
function fakeHistory() {
    const entries = ["/list"];
    const pending = [];

    const api = {
        pushes: 0,
        backs: 0,
        get depth() {
            return entries.length;
        },
        get current() {
            return entries[entries.length - 1];
        },
        pushState(state, _title, url) {
            api.pushes++;
            entries.push(url ?? api.current);
        },
        back() {
            api.backs++;
            pending.push(() => {
                entries.pop();
                window.dispatchEvent(new PopStateEvent("popstate"));
            });
        },
        /** Flush the queued pops, the way the browser does on its own turn. */
        settle() {
            while (pending.length) pending.shift()();
        },
        /** A real Back press: pops and notifies, with nothing queued by us. */
        userPressesBack() {
            entries.pop();
            window.dispatchEvent(new PopStateEvent("popstate"));
        },
    };

    return api;
}

/** One overlay, driven by a boolean, closing itself the way AppModal does. */
function overlay(shown, onClose) {
    return defineComponent({
        setup() {
            useBackButtonClose({ isOpen: () => shown.value, onClose });

            return () => h("div");
        },
    });
}

describe("useBackButtonClose", () => {
    let stack;

    beforeEach(() => {
        __resetBackButtonClose();
        stack = fakeHistory();
        vi.stubGlobal("history", stack);
    });

    it("adds one entry while an overlay is open and takes it back", async () => {
        const shown = ref(false);
        mount(
            overlay(shown, () => {
                shown.value = false;
            }),
        );

        shown.value = true;
        await nextTick();
        expect(stack.depth).toBe(2);

        shown.value = false;
        await nextTick();
        await Promise.resolve();
        stack.settle();
        expect(stack.depth).toBe(1);
    });

    /**
     * The case that threw people out of every list: a row-actions menu closes
     * in the same tick as the confirmation it opens. One entry goes, another
     * arrives, and the pending pop must not eat the page's own entry.
     */
    it("survives one overlay closing as another opens", async () => {
        const menu = ref(false);
        const confirm = ref(false);
        mount(
            overlay(menu, () => {
                menu.value = false;
            }),
        );
        mount(
            overlay(confirm, () => {
                confirm.value = false;
            }),
        );

        menu.value = true;
        await nextTick();

        menu.value = false;
        confirm.value = true;
        await nextTick();
        await Promise.resolve();
        stack.settle();

        expect(stack.depth).toBe(2);
        expect(stack.current).toBe("/list");

        confirm.value = false;
        await nextTick();
        await Promise.resolve();
        stack.settle();

        expect(stack.depth).toBe(1);
        expect(stack.current).toBe("/list");
    });

    /**
     * The assertion that pins the fix: swapping one overlay for another must
     * touch history *not at all*. The version that shipped popped and pushed
     * around that instant, and because the pop lands a turn later the two
     * crossed - which is how a Cancel ended up on the module dashboard.
     */
    it("touches history not at all when one overlay replaces another", async () => {
        const menu = ref(false);
        const confirm = ref(false);
        mount(
            overlay(menu, () => {
                menu.value = false;
            }),
        );
        mount(
            overlay(confirm, () => {
                confirm.value = false;
            }),
        );

        menu.value = true;
        await nextTick();
        stack.settle();

        const pushes = stack.pushes;
        const backs = stack.backs;

        menu.value = false;
        confirm.value = true;
        await nextTick();
        await Promise.resolve();
        stack.settle();

        expect(stack.pushes - pushes).toBe(0);
        expect(stack.backs - backs).toBe(0);
        expect(stack.depth).toBe(2);
    });

    /** Cancelling out of that chain must leave the reader where they were. */
    it("does not leave the page when the second overlay is cancelled", async () => {
        const menu = ref(false);
        const confirm = ref(false);
        mount(
            overlay(menu, () => {
                menu.value = false;
            }),
        );
        mount(
            overlay(confirm, () => {
                confirm.value = false;
            }),
        );

        menu.value = true;
        await nextTick();
        menu.value = false;
        confirm.value = true;
        await nextTick();
        await Promise.resolve();
        stack.settle();

        confirm.value = false;
        await nextTick();
        await Promise.resolve();
        stack.settle();

        // One entry, the page itself: nothing was popped past it.
        expect(stack.depth).toBe(1);
    });

    it("closes the topmost overlay when the user presses Back", async () => {
        const outer = ref(false);
        const inner = ref(false);
        mount(
            overlay(outer, () => {
                outer.value = false;
            }),
        );
        mount(
            overlay(inner, () => {
                inner.value = false;
            }),
        );

        outer.value = true;
        await nextTick();
        inner.value = true;
        await nextTick();

        stack.userPressesBack();
        await nextTick();

        expect(inner.value).toBe(false);
        expect(outer.value).toBe(true);

        stack.userPressesBack();
        await nextTick();

        expect(outer.value).toBe(false);
    });

    /** An overlay torn down while open still gives its entry back. */
    it("gives the entry back when an open overlay unmounts", async () => {
        const shown = ref(false);
        const wrapper = mount(
            overlay(shown, () => {
                shown.value = false;
            }),
        );

        shown.value = true;
        await nextTick();
        expect(stack.depth).toBe(2);

        wrapper.unmount();
        await Promise.resolve();
        stack.settle();
        expect(stack.depth).toBe(1);
    });

    /**
     * The notes bug: closing the delete confirmation popped our entry, the
     * page's own listener took it for Back, and reopened the note it had
     * just deleted. The page must not hear a pop it did not cause.
     */
    it("keeps its own pop from reaching the page's listeners", async () => {
        const page = vi.fn();
        window.addEventListener("popstate", page);
        const shown = ref(false);
        mount(
            overlay(shown, () => {
                shown.value = false;
            }),
        );

        shown.value = true;
        await nextTick();
        shown.value = false;
        await nextTick();
        await Promise.resolve();
        stack.settle();

        expect(stack.depth).toBe(1);
        expect(page).not.toHaveBeenCalled();
        window.removeEventListener("popstate", page);
    });

    it("keeps a Back that only closes an overlay from the page too", async () => {
        const page = vi.fn();
        window.addEventListener("popstate", page);
        const shown = ref(false);
        mount(
            overlay(shown, () => {
                shown.value = false;
            }),
        );

        shown.value = true;
        await nextTick();
        stack.userPressesBack();
        await nextTick();

        expect(shown.value).toBe(false);
        expect(page).not.toHaveBeenCalled();
        window.removeEventListener("popstate", page);
    });

    it("lets a Back with no overlay open reach the page", async () => {
        const page = vi.fn();
        window.addEventListener("popstate", page);
        mount(overlay(ref(false), () => {}));

        stack.pushState(null, "", "/list/2");
        stack.userPressesBack();

        expect(page).toHaveBeenCalledTimes(1);
        window.removeEventListener("popstate", page);
    });

    it("says when the last overlay entry is gone", async () => {
        const shown = ref(false);
        mount(
            overlay(shown, () => {
                shown.value = false;
            }),
        );

        shown.value = true;
        await nextTick();
        shown.value = false;
        await nextTick();
        await Promise.resolve();

        let settled = false;
        overlaysSettled().then(() => {
            settled = true;
        });
        await Promise.resolve();
        expect(settled).toBe(false);

        stack.settle();
        await Promise.resolve();
        expect(settled).toBe(true);
    });

    it("settles at once when no overlay was open", async () => {
        await expect(overlaysSettled()).resolves.toBeUndefined();
    });
});
