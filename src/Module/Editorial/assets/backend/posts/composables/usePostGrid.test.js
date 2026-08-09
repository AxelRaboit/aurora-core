import { describe, it, expect, vi } from "vitest";
import { ref } from "vue";
import { usePostGrid } from "./usePostGrid.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

function makeLayout(overrides = {}) {
    return ref({ enabled: true, snap: 4, zones: [], ...overrides });
}

function makeContent() {
    return ref({ zones: {} });
}

/** The pair the composable takes, since the grid is stored in two halves. */
function make() {
    const layout = makeLayout();
    const content = makeContent();

    return { layout, content, api: usePostGrid(layout, content) };
}

describe("usePostGrid", () => {
    it("exposes every name it returns", () => {
        for (const [key, value] of Object.entries(make().api)) {
            expect(value, `${key} is missing`).toBeDefined();
        }
    });

    it("adds, reorders and removes zones", () => {
        const { addZone, moveZone, removeZone, zones } = make().api;

        addZone("text");
        addZone("media");
        expect(zones.value.map((z) => z.type)).toEqual(["text", "media"]);

        moveZone(0, 1);
        expect(zones.value.map((z) => z.type)).toEqual(["media", "text"]);

        removeZone(0);
        expect(zones.value.map((z) => z.type)).toEqual(["text"]);
    });

    it("refuses to move a zone past either end", () => {
        const { addZone, moveZone, zones } = make().api;

        addZone("text");
        addZone("media");
        moveZone(0, -1);
        moveZone(1, 1);

        expect(zones.value.map((z) => z.type)).toEqual(["text", "media"]);
    });

    it("stops adding zones at the cap", () => {
        const { addZone, canAddZone, zones } = make().api;

        for (let index = 0; index < 70; index += 1) {
            addZone("text");
        }

        expect(zones.value).toHaveLength(60);
        expect(canAddZone.value).toBe(false);
    });

    it("gives every zone a distinct id", () => {
        const { layout, api } = make();

        for (let index = 0; index < 6; index += 1) {
            api.addZone("text");
        }

        expect(new Set(layout.value.zones.map((z) => z.id)).size).toBe(6);
    });

    /**
     * A counter would hand a new zone the id of the one just deleted, and every
     * other language would greet it with the removed zone's content.
     */
    it("never hands a new zone the id of a removed one", () => {
        const { layout, api } = make();

        api.addZone("text");
        const first = layout.value.zones[0].id;
        api.removeZone(0);
        api.addZone("text");

        expect(layout.value.zones[0].id).not.toBe(first);
    });

    // ── Which half each field goes to ─────────────────────────────────────

    it("sends each field to the half it belongs to", () => {
        const { layout, content, api } = make();

        api.addZone("media");
        const fields = api.zoneFields(0);

        fields.alt.value = "Le chantier";
        fields.caption.value = "Vue depuis la grue";
        fields.media.value = { id: 12, url: "/uploads/x.png" };

        const id = layout.value.zones[0].id;

        expect(content.value.zones[id].alt).toBe("Le chantier");
        expect(content.value.zones[id].caption).toBe("Vue depuis la grue");
        expect(layout.value.zones[0].mediaId).toBe(12);
        expect(layout.value.zones[0].alt).toBeUndefined();
    });

    it("treats a video address as content, not arrangement", () => {
        const { layout, content, api } = make();

        api.addZone("video");
        api.zoneFields(0).url.value = "https://vimeo.com/123456789";

        const id = layout.value.zones[0].id;

        expect(content.value.zones[id].url).toBe("https://vimeo.com/123456789");
        expect(layout.value.zones[0].url).toBeUndefined();
    });

    it("treats a linked publication as arrangement, not content", () => {
        const { layout, content, api } = make();

        api.addZone("post");
        api.zoneFields(0).postId.value = 42;

        expect(layout.value.zones[0].postId).toBe(42);
        expect(
            content.value.zones[layout.value.zones[0].id].postId,
        ).toBeUndefined();
    });

    /** Re-pointing `content` is what switching the locale tab does. */
    it("keeps the arrangement when the language changes", () => {
        const layout = makeLayout();
        const french = makeContent();
        const api = usePostGrid(layout, french);

        api.addZone("text");
        api.zoneFields(0).caption.value = "Légende";
        const before = JSON.stringify(layout.value);

        const german = makeContent();
        const other = usePostGrid(layout, german);
        other.zoneFields(0).caption.value = "Bildunterschrift";

        const id = layout.value.zones[0].id;

        expect(JSON.stringify(layout.value)).toBe(before);
        expect(french.value.zones[id].caption).toBe("Légende");
        expect(german.value.zones[id].caption).toBe("Bildunterschrift");
    });

    it("drops a zone's content when the zone goes", () => {
        const { layout, content, api } = make();

        api.addZone("text");
        const id = layout.value.zones[0].id;
        api.zoneFields(0).caption.value = "Légende";

        api.removeZone(0);

        expect(content.value.zones[id]).toBeUndefined();
    });

    // ── Widths and the snap ───────────────────────────────────────────────

    it("lands a width on the current step", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 25;

        expect(layout.value.zones[0].span.lg).toBe(
            24,
            "25 is not reachable in twelfths",
        );
    });

    it("reaches finer widths on a finer step", () => {
        const { layout, api } = make();

        api.snap.value = 1;
        api.addZone("text");
        api.zoneFields(0).width.value = 25;

        expect(layout.value.zones[0].span.lg).toBe(25);
    });

    it("never lets a zone reach zero or overflow the grid", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 0;
        expect(layout.value.zones[0].span.lg).toBe(4, "one step, not nothing");

        api.zoneFields(0).width.value = 900;
        expect(layout.value.zones[0].span.lg).toBe(48);
    });

    /**
     * A layout should not shift because someone went looking for finer
     * control; only what they change afterwards lands on the new step.
     */
    it("leaves placed zones alone when the step changes", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 24;

        api.snap.value = 1;

        expect(layout.value.zones[0].span.lg).toBe(24);
    });

    it("keeps a zone full width below the large breakpoint", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 12;

        expect(layout.value.zones[0].span.base).toBe(
            48,
            "side-by-side on a phone is two columns of four words",
        );
    });

    it("offers the three steps and the four zone types", () => {
        const { snapOptions, typeOptions } = make().api;

        expect(snapOptions.value.map((o) => o.value)).toEqual([4, 2, 1]);
        expect(typeOptions.value.map((o) => o.value)).toEqual([
            "text",
            "media",
            "post",
            "video",
        ]);
    });
});
