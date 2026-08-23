import { beforeEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { useTopbarNavigation } from "./useTopbarNavigation.js";

/** The smallest component that uses the composable the way the real one does. */
function mountHolder() {
    return mount({
        setup() {
            return { api: useTopbarNavigation() };
        },
        template: "<i />",
    });
}

describe("the header's navigation buttons", () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    // `history.back()`, not the referrer: the referrer says where this page was
    // reached from once, says nothing about forward, and is empty after a
    // POST-redirect-GET.
    it("moves through history rather than guessing from the referrer", () => {
        const back = vi
            .spyOn(window.history, "back")
            .mockImplementation(() => {});
        const forward = vi
            .spyOn(window.history, "forward")
            .mockImplementation(() => {});

        const { api } = mountHolder().vm;
        api.back();
        api.forward();

        expect(back).toHaveBeenCalledOnce();
        expect(forward).toHaveBeenCalledOnce();
    });

    /**
     * The only half of the question the platform answers. `history.length` of 1
     * means this page is where the tab started, so there is nowhere to go back
     * to - and nothing anywhere says whether a *forward* entry exists, which is
     * why that button is never disabled.
     */
    it("knows when there is nowhere to go back to", () => {
        vi.spyOn(window.history, "length", "get").mockReturnValue(1);
        expect(mountHolder().vm.api.canGoBack.value).toBe(false);

        vi.spyOn(window.history, "length", "get").mockReturnValue(4);
        expect(mountHolder().vm.api.canGoBack.value).toBe(true);
    });
});

describe("the reload button", () => {
    let reload;
    let unregister;

    beforeEach(() => {
        vi.restoreAllMocks();

        reload = vi.fn();
        unregister = vi.fn().mockResolvedValue(true);

        // jsdom has neither, and the composable is written to survive a browser
        // that refuses either one - so both are given, then taken away.
        navigator.serviceWorker = {
            getRegistrations: vi.fn().mockResolvedValue([{ unregister }]),
        };
        window.caches = {
            keys: vi.fn().mockResolvedValue(["qsse-v3", "aurora-v1"]),
            delete: vi.fn().mockResolvedValue(true),
        };
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: true }));
        Object.defineProperty(window, "location", {
            configurable: true,
            value: { href: "http://localhost/backend", reload },
        });
    });

    /**
     * The three things a script can actually reach, in the order that matters.
     * A service worker registered by another project on the same localhost
     * origin will answer for this one - found happening on this very port.
     */
    it("clears what a script is allowed to clear, then reloads", async () => {
        await mountHolder().vm.api.hardReload();

        expect(unregister).toHaveBeenCalledOnce();
        expect(window.caches.delete).toHaveBeenCalledTimes(2);
        expect(fetch).toHaveBeenCalledWith(
            "http://localhost/backend",
            expect.objectContaining({ cache: "reload" }),
        );
        expect(reload).toHaveBeenCalledOnce();
    });

    // Every step is best-effort: a browser that refuses one still owes the
    // reload, which is the part that was asked for.
    it("reloads even when clearing fails", async () => {
        navigator.serviceWorker.getRegistrations = vi
            .fn()
            .mockRejectedValue(new Error("refusé"));

        await mountHolder().vm.api.hardReload();

        expect(reload).toHaveBeenCalledOnce();
    });

    it("cannot be started twice while it is working", async () => {
        const { api } = mountHolder().vm;

        await Promise.all([api.hardReload(), api.hardReload()]);

        expect(reload).toHaveBeenCalledOnce();
    });
});
