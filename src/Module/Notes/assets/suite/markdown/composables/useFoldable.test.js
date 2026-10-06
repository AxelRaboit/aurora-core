import { describe, expect, it } from "vitest";
import { nextTick } from "vue";
import { useFoldable } from "./useFoldable.js";

/**
 * A folded control, and the trap of wiring it straight onto a click.
 */
describe("useFoldable", () => {
    it("opens, and hands the keyboard to what appeared", async () => {
        const { open, box, reveal } = useFoldable();
        const field = document.createElement("input");
        const host = document.createElement("div");

        host.append(field);
        document.body.append(host);
        box.value = host;

        await reveal();

        expect(open.value).toBe(true);
        expect(document.activeElement).toBe(field);

        host.remove();
    });

    /**
     * Wired as is on a `@click`, `reveal` receives the event instead of the
     * selector. `querySelector` refused it by throwing, the promise
     * rejected, and Vue bubbled that rejection up to the page's
     * `errorCaptured`, which replaced the whole library with its error
     * screen: clicking the magnifier emptied the screen.
     */
    it("survives being handed a click event instead of a selector", async () => {
        const { open, box, reveal } = useFoldable();
        const field = document.createElement("input");
        const host = document.createElement("div");

        host.append(field);
        document.body.append(host);
        box.value = host;

        await expect(reveal(new MouseEvent("click"))).resolves.toBeUndefined();

        expect(open.value).toBe(true);
        expect(document.activeElement).toBe(field);

        host.remove();
    });

    it("folds back", async () => {
        const { open, reveal, fold } = useFoldable();

        await reveal();
        await nextTick();
        fold();

        expect(open.value).toBe(false);
    });
});
