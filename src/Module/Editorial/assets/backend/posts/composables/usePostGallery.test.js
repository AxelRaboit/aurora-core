import { describe, it, expect } from "vitest";
import { reactive } from "vue";
import { GALLERY_MAX_ITEMS, usePostGallery } from "./usePostGallery.js";

function setup(items = []) {
    const layout = reactive({
        enabled: false,
        layout: "grid",
        columns: 3,
        ratio: "natural",
        items,
    });
    const words = reactive({ items: {} });

    return { layout, words, api: usePostGallery(layout, words) };
}

describe("usePostGallery", () => {
    it("appends a picked picture and gives it somewhere to write", () => {
        const { layout, words, api } = setup();

        expect(api.addItem({ id: 7, url: "/a.jpg" })).toBe(true);
        expect(layout.items).toHaveLength(1);
        expect(words.items[layout.items[0].id]).toEqual({
            alt: "",
            caption: "",
        });
    });

    /**
     * Refused rather than silently ignored: the author picked it on purpose, and
     * a picker that closes with nothing happening reads as broken. The panel
     * turns the `false` into a message.
     */
    it("refuses a picture that is already in", () => {
        const { layout, api } = setup();
        api.addItem({ id: 7 });

        expect(api.addItem({ id: 7 })).toBe(false);
        expect(layout.items).toHaveLength(1);
    });

    it("refuses anything once the cap is reached", () => {
        const items = Array.from({ length: GALLERY_MAX_ITEMS }, (_, i) => ({
            id: `i${i}`,
            mediaId: i + 1,
        }));
        const { api } = setup(items);

        expect(api.isFull.value).toBe(true);
        expect(api.addItem({ id: 999 })).toBe(false);
    });

    it("drops an item and the words that went with it", () => {
        const { layout, words, api } = setup();
        api.addItem({ id: 7 });
        const id = layout.items[0].id;
        words.items[id].caption = "Rue de la Paix";

        api.removeItem(id);

        expect(layout.items).toEqual([]);
        expect(words.items[id]).toBeUndefined();
    });

    it("moves one step and stops at the ends rather than wrapping", () => {
        const { layout, api } = setup([
            { id: "a", mediaId: 1 },
            { id: "b", mediaId: 2 },
        ]);

        api.moveItem("a", -1);
        expect(layout.items.map((i) => i.id)).toEqual(["a", "b"]);

        api.moveItem("a", 1);
        expect(layout.items.map((i) => i.id)).toEqual(["b", "a"]);

        api.moveItem("a", 1);
        expect(layout.items.map((i) => i.id)).toEqual(["b", "a"]);
    });

    /**
     * A gallery loaded from the server carries words for the items it had.
     * Reading an item added since has to create its entry, or the field bound to
     * it drops what is typed.
     */
    it("creates the words for an item that has none", () => {
        const { api } = setup([{ id: "fresh", mediaId: 1 }]);

        expect(api.wordsFor("fresh")).toEqual({ alt: "", caption: "" });
    });

    /**
     * A drag emits the whole order at once. Filtered against the ids already in
     * rather than assigned as it arrives: the value comes from a library, and an
     * item this gallery does not know about would reach the column with no media
     * behind it.
     */
    it("takes a whole new order from a drag", () => {
        const { layout, api } = setup([
            { id: "a", mediaId: 1 },
            { id: "b", mediaId: 2 },
            { id: "c", mediaId: 3 },
        ]);

        api.reorder([{ id: "c" }, { id: "a" }, { id: "b" }]);

        expect(layout.items.map((i) => i.id)).toEqual(["c", "a", "b"]);
        // The stored objects, not the ones the drag handed over.
        expect(layout.items[0].mediaId).toBe(3);
    });

    it("keeps what is on screen when the incoming order is not this gallery's", () => {
        const { layout, api } = setup([
            { id: "a", mediaId: 1 },
            { id: "b", mediaId: 2 },
        ]);

        api.reorder([{ id: "a" }, { id: "elsewhere" }]);
        api.reorder("nonsense");
        api.reorder([{ id: "a" }]);

        expect(layout.items.map((i) => i.id)).toEqual(["a", "b"]);
    });

    /**
     * One at a time, not all at once: thirty parallel multipart requests at one
     * endpoint is how the slow one gets slower. The upload is injected, so what
     * is under test is the counting rather than an endpoint.
     */
    it("imports several files in order and appends each", async () => {
        const { layout, api } = setup();
        const seen = [];
        const upload = async (file) => {
            seen.push(file.name);

            return { id: seen.length, url: `/${file.name}` };
        };

        const result = await api.importFiles(
            [{ name: "a.jpg" }, { name: "b.jpg" }],
            upload,
        );

        expect(seen).toEqual(["a.jpg", "b.jpg"]);
        expect(result).toEqual({ added: 2, skipped: 0 });
        expect(layout.items).toHaveLength(2);
        expect(api.importing.value).toBe(false);
    });

    /**
     * What did not go in has to be counted, or the author has twelve pictures on
     * their disk, eight on the page, and nothing telling them why.
     */
    it("counts what it left out, whatever the reason", async () => {
        const { api } = setup([{ id: "a", mediaId: 1 }]);
        const upload = async (file) => (file.ok ? { id: file.id } : null);

        const result = await api.importFiles(
            [{ ok: true, id: 1 }, { ok: false }, { ok: true, id: 2 }],
            upload,
        );

        // The duplicate, the refused file, and one that went in.
        expect(result).toEqual({ added: 1, skipped: 2 });
    });

    it("does not start a second import over the first", async () => {
        const { api } = setup();
        let calls = 0;
        const upload = async () => {
            calls += 1;

            return { id: calls };
        };

        const first = api.importFiles([{ name: "a" }], upload);
        const second = await api.importFiles([{ name: "b" }], upload);
        await first;

        expect(second).toEqual({ added: 0, skipped: 0 });
        expect(calls).toBe(1);
    });

    // The settings are writable computeds so the panel never mutates a prop.
    it("writes a setting back, and refuses a value outside the list", () => {
        const { layout, api } = setup();

        api.mode.value = "masonry";
        api.columns.value = 5;
        expect(layout.layout).toBe("masonry");
        expect(layout.columns).toBe(5);

        api.mode.value = "carousel";
        api.columns.value = 97;
        expect(layout.layout).toBe("grid");
        expect(layout.columns).toBe(3);
    });
});
