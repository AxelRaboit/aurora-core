import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { ref, nextTick } from "vue";
import { useServerPreview } from "./useServerPreview.js";

const request = vi.fn();

vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

// The real one registers onUnmounted, which needs a component instance.
vi.mock("@/shared/composables/useDebounce.js", () => ({
    useDebounce: (fn, delay) => {
        let timer = null;

        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    },
}));

/**
 * What a caller passes: the payload to send and the sources to watch. Written
 * once here because every test needs the same pair, and the shape of that pair
 * is the composable's actual contract.
 */
function preview(layout, texts) {
    return useServerPreview(
        () => ({ layout: layout.value, texts: texts.value }),
        [layout, texts],
        "/preview",
    );
}

/** Lets the debounce fire and the awaited request settle. */
async function settle() {
    vi.runAllTimers();
    await nextTick();
    await Promise.resolve();
    await Promise.resolve();
}

describe("useServerPreview", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        request.mockReset();
        request.mockResolvedValue({ success: true, html: "<header></header>" });
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("sends both halves, because a preview is per language", async () => {
        const layout = ref({
            enabled: true,
            items: [{ id: "a1", type: "text" }],
        });
        const texts = ref({ items: { a1: { title: "Bonjour" } } });

        preview(layout, texts);
        await settle();

        expect(request).toHaveBeenCalledWith("/preview", {
            layout: { enabled: true, items: [{ id: "a1", type: "text" }] },
            texts: { items: { a1: { title: "Bonjour" } } },
        });
    });

    it("redraws when only the words change", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: { a1: { title: "Bonjour" } } });

        preview(layout, texts);
        await settle();
        expect(request).toHaveBeenCalledTimes(1);

        texts.value.items.a1.title = "Guten Tag";
        await nextTick();
        await settle();

        expect(request).toHaveBeenCalledTimes(2);
        expect(request.mock.calls[1][1].texts.items.a1.title).toBe("Guten Tag");
    });

    it("debounces so typing a title is not a request per keystroke", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: {} });

        preview(layout, texts);

        for (const title of ["B", "Bo", "Bon"]) {
            texts.value.items.a1 = { title };
            await nextTick();
        }

        await settle();

        expect(request).toHaveBeenCalledTimes(1);
    });

    /**
     * A slow answer must not overwrite a newer one: without the ticket the
     * preview would settle on whatever the server happened to finish last.
     */
    it("ignores an answer that a newer request has already overtaken", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: {} });

        let resolveFirst;
        request.mockImplementationOnce(
            () => new Promise((resolve) => (resolveFirst = resolve)),
        );
        request.mockResolvedValueOnce({ success: true, html: "<p>récent</p>" });

        const { html, refresh } = preview(layout, texts);

        const first = refresh();
        await refresh();

        expect(html.value).toBe("<p>récent</p>");

        resolveFirst({ success: true, html: "<p>périmé</p>" });
        await first;

        expect(html.value).toBe("<p>récent</p>");
    });

    it("keeps the last good markup when a request fails", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: {} });

        const { html, refresh } = preview(layout, texts);
        await settle();
        expect(html.value).toBe("<header></header>");

        request.mockResolvedValueOnce(null);
        await refresh();

        expect(html.value).toBe(
            "<header></header>",
            "a blank panel reads as a broken banner",
        );
    });

    /**
     * A translation that has not finished loading is undefined, and the
     * snapshot runs inside a debounced callback - a throw there would surface
     * 400ms later as a preview that simply stopped updating.
     *
     * The half that is missing drops out of the payload rather than arriving
     * empty, which reads the same on the server: every reader of these guards
     * on `is_array(... ?? null)`.
     */
    it("survives a half loaded translation", async () => {
        const layout = ref({ items: [] });
        const texts = ref(undefined);

        preview(layout, texts);
        await settle();

        expect(request).toHaveBeenCalledTimes(1);
        expect(request.mock.calls[0][1].layout).toEqual({ items: [] });
    });

    // ── Previews nobody is looking at ─────────────────────────────────────

    /** A caller that gates the preview on something being open. */
    function gatedPreview(layout, texts, open) {
        return useServerPreview(
            () => ({ layout: layout.value, texts: texts.value }),
            [layout, texts],
            "/preview",
            { enabled: () => open.value },
        );
    }

    it("asks for nothing while nobody is looking", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: {} });
        const open = ref(false);

        gatedPreview(layout, texts, open);
        await settle();

        texts.value.items.a1 = { title: "Bonjour" };
        await nextTick();
        await settle();

        expect(request).not.toHaveBeenCalled();
    });

    it("catches up as soon as somebody opens it", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: {} });
        const open = ref(false);

        gatedPreview(layout, texts, open);
        texts.value.items.a1 = { title: "Bonjour" };
        await nextTick();
        await settle();

        open.value = true;
        await nextTick();
        await settle();

        expect(request).toHaveBeenCalledTimes(1);
        expect(request.mock.calls[0][1].texts.items.a1.title).toBe("Bonjour");
    });

    /** Re-opening a preview nothing has moved under should cost no request. */
    it("does not ask again when nothing has changed since the last answer", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: {} });
        const open = ref(true);

        gatedPreview(layout, texts, open);
        await settle();
        expect(request).toHaveBeenCalledTimes(1);

        open.value = false;
        await nextTick();
        open.value = true;
        await nextTick();
        await settle();

        expect(request).toHaveBeenCalledTimes(1);
    });

    it("still redraws live for a caller that never gates it", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: {} });

        preview(layout, texts);
        await settle();

        texts.value.items.a1 = { title: "Bonjour" };
        await nextTick();
        await settle();

        expect(request).toHaveBeenCalledTimes(2);
    });

    it("reports that a request is in flight", async () => {
        const layout = ref({ items: [] });
        const texts = ref({ items: {} });

        const { loading, refresh } = preview(layout, texts);

        const pending = refresh();
        expect(loading.value).toBe(true);

        await pending;
        expect(loading.value).toBe(false);
    });
});
