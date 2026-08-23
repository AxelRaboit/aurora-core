import { describe, it, expect, vi } from "vitest";
import { ref } from "vue";
import { placeZones, planMove, usePostGrid } from "./usePostGrid.js";

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

    it("exchanges two zones wherever they sit", () => {
        const { addZone, swapZones, zones } = make().api;

        addZone("text");
        addZone("media");
        addZone("video");

        swapZones(0, 2);

        expect(zones.value.map((z) => z.type)).toEqual([
            "video",
            "media",
            "text",
        ]);
    });

    /**
     * Content is keyed by zone id, so a swap must not move it - the ids travel
     * with the zones. Getting this wrong would hand a zone the other one's
     * text in every language at once.
     */
    it("leaves each zone's content attached to it through a swap", () => {
        const { layout, content, api } = make();

        api.addZone("text");
        api.addZone("text");
        api.zoneFields(0).caption.value = "Première";
        api.zoneFields(1).caption.value = "Seconde";

        const [first, second] = layout.value.zones.map((z) => z.id);
        api.swapZones(0, 1);

        expect(layout.value.zones.map((z) => z.id)).toEqual([second, first]);
        expect(content.value.zones[first].caption).toBe("Première");
        expect(content.value.zones[second].caption).toBe("Seconde");
    });

    it("ignores a swap that names a zone twice or a zone that is not there", () => {
        const { addZone, swapZones, zones } = make().api;

        addZone("text");
        addZone("media");

        swapZones(0, 0);
        swapZones(0, 9);
        swapZones(-1, 1);

        expect(zones.value.map((z) => z.type)).toEqual(["text", "media"]);
    });

    /**
     * Converting keeps the id, so every other language keeps what it holds for
     * the zone, and the width and place in the order survive.
     */
    it("converts a zone without disturbing anything else about it", () => {
        const { layout, content, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 16;
        const id = layout.value.zones[0].id;
        content.value.zones[id].alt = "Déjà écrit";

        api.zoneFields(0).type.value = "media";

        expect(layout.value.zones[0].id).toBe(id);
        expect(layout.value.zones[0].span.lg).toBe(16);
        expect(content.value.zones[id].alt).toBe("Déjà écrit");
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

    it("treats where a zone sits as arrangement, not content", () => {
        const { layout, content, api } = make();

        api.addZone("text");
        api.zoneFields(0).offset.value = 12;
        api.zoneFields(0).newRow.value = true;

        const id = layout.value.zones[0].id;

        expect(layout.value.zones[0].offset).toBe(12);
        expect(layout.value.zones[0].newRow).toBe(true);
        expect(content.value.zones[id]).not.toHaveProperty("offset");
    });

    // Zero has to survive the snap, whose floor is the step: an offset rounded
    // up to 4 would leave no way back to the flow.
    it("lets an offset go back to nothing", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).offset.value = 12;
        api.zoneFields(0).offset.value = 0;

        expect(layout.value.zones[0].offset).toBe(0);
    });

    // Otherwise the server, which clamps on the way in, would hand back a
    // layout the editor never showed.
    it("gives back an offset the row can no longer hold when a zone widens", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).offset.value = 24;
        api.zoneFields(0).width.value = 48;

        expect(layout.value.zones[0].offset).toBe(0);
    });

    // Deliberately the other way round from the video address just below: a
    // video is localised, a picture is the same picture in every language and
    // only its description changes.
    // Design, like the ratio beside it: how big a picture is printed is written
    // once for every language.
    it("treats how big a picture is printed as arrangement", () => {
        const { layout, content, api } = make();

        api.addZone("media");
        api.zoneFields(0).scale.value = 50;

        const id = layout.value.zones[0].id;

        expect(layout.value.zones[0].scale).toBe(50);
        expect(content.value.zones[id] ?? {}).not.toHaveProperty("scale");
    });

    it("treats an image address as arrangement, not content", () => {
        const { layout, content, api } = make();

        api.addZone("media");
        api.zoneFields(0).mediaUrl.value = "https://picsum.photos/800/600";

        const id = layout.value.zones[0].id;

        expect(layout.value.zones[0].mediaUrl).toBe(
            "https://picsum.photos/800/600",
        );
        expect(content.value.zones[id] ?? {}).not.toHaveProperty("mediaUrl");
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

    it("offers every fraction on a whole number of columns", () => {
        const { widthOptions } = make().api;

        expect(widthOptions.value.map((o) => o.value)).toEqual([
            12, 16, 24, 32, 36, 48,
        ]);
    });

    it("names widths the snap can always reach", () => {
        // Every fraction is a multiple of four, so `clampToSnap` leaves them
        // alone at the coarsest step as well as the finest. Were one not, a
        // fraction would silently land somewhere else and the button would be
        // lying about what it does.
        const { layout, api } = make();

        for (const step of [4, 2, 1]) {
            layout.value.snap = step;

            for (const option of api.widthOptions.value) {
                api.addZone("text");
                const index = layout.value.zones.length - 1;
                api.zoneFields(index).width.value = option.value;

                expect(
                    layout.value.zones[index].span.lg,
                    `${option.label} on step ${step}`,
                ).toBe(option.value);
            }
        }
    });

    it("starts a zone at its own proportions", () => {
        const { layout, api } = make();

        api.addZone("media");

        expect(layout.value.zones[0].ratio).toBe("natural");
    });

    it("treats the crop as arrangement, not content", () => {
        // How a picture is cropped is design, written once - the same argument
        // that puts the span on the post rather than on the translation.
        const { layout, content, api } = make();

        api.addZone("media");
        api.zoneFields(0).ratio.value = "1x1";

        expect(layout.value.zones[0].ratio).toBe("1x1");
        expect(
            content.value.zones[layout.value.zones[0].id],
        ).not.toHaveProperty("ratio");
    });

    it("offers every shape the normaliser accepts", () => {
        expect(make().api.ratioOptions.value.map((o) => o.value)).toEqual([
            "natural",
            "16x9",
            "4x3",
            "1x1",
            "3x4",
            "fill",
        ]);
    });

    it("offers the three steps and the five zone types", () => {
        const { snapOptions, typeOptions } = make().api;

        expect(snapOptions.value.map((o) => o.value)).toEqual([4, 2, 1]);
        expect(typeOptions.value.map((o) => o.value)).toEqual([
            "text",
            "media",
            "post",
            "video",
            "stack",
        ]);
    });

    /** Depth stops at one, and the editor must not offer what the server drops. */
    it("does not offer a stack inside a stack", () => {
        expect(make().api.leafTypeOptions.value.map((o) => o.value)).toEqual([
            "text",
            "media",
            "post",
            "video",
        ]);
    });

    // ── Stacks ────────────────────────────────────────────────────────────

    it("adds zones to a stack and shares the height evenly", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "media");

        const children = layout.value.zones[0].children;

        expect(children.map((c) => c.type)).toEqual(["media", "media"]);
        expect(children.map((c) => c.span.lg)).toEqual(
            [24, 24],
            "two zones, half the height each - and they sum to 48 so the fraction row can say so",
        );

        api.addChild(0, "text");
        expect(layout.value.zones[0].children.map((c) => c.span.lg)).toEqual([
            16, 16, 16,
        ]);
    });

    it("re-shares the height when a zone leaves the stack", () => {
        const { layout, content, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "media");
        api.addChild(0, "media");

        const goneId = layout.value.zones[0].children[1].id;
        api.removeChild(0, 1);

        expect(layout.value.zones[0].children.map((c) => c.span.lg)).toEqual([
            24, 24,
        ]);
        expect(content.value.zones[goneId]).toBeUndefined();
    });

    it("reorders inside a stack without touching the rest", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "text");
        api.addChild(0, "media");
        api.moveChild(0, 0, 1);

        expect(layout.value.zones[0].children.map((c) => c.type)).toEqual([
            "media",
            "text",
        ]);
    });

    it("reaches a stacked zone's own fields", () => {
        const { layout, content, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.zoneFields(0, 0).alt.value = "Vue depuis la treille";

        const childId = layout.value.zones[0].children[0].id;

        expect(content.value.zones[childId].alt).toBe("Vue depuis la treille");
        expect(api.zoneFields(0).alt.value).not.toBe("Vue depuis la treille");
    });

    /**
     * The spans are grow factors against each other, not against 48. Two zones
     * both set to 2/3 are two halves, and the panel has to be able to say so
     * rather than print "2/3" twice.
     */
    it("reports the share a zone really gets, not the fraction it was given", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "media");

        expect(api.childShare(0, 0)).toBe(50);

        layout.value.zones[0].children[0].span.lg = 32;
        layout.value.zones[0].children[1].span.lg = 16;

        expect(api.childShare(0, 0)).toBe(67);
        expect(api.childShare(0, 1)).toBe(33);
    });

    /**
     * The point of rebalancing: a button that says "2/3" has to give two
     * thirds. Left alone, 32 beside an untouched 24 is 57% and 43% - close
     * enough to look right and wrong enough to be a bug report.
     */
    it("gives the rest of the height back when one zone's share changes", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "media");

        api.zoneFields(0, 0).width.value = 32;

        expect(layout.value.zones[0].children.map((c) => c.span.lg)).toEqual([
            32, 16,
        ]);
        expect(api.childShare(0, 0)).toBe(67);
        expect(api.childShare(0, 1)).toBe(33);
    });

    it("splits the rest in proportion to what the others already had", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "media");
        api.addChild(0, "media");

        // A stack an author has already tuned: 8 / 16 / 24 out of 48.
        const children = layout.value.zones[0].children;
        children[1].span.lg = 16;
        children[2].span.lg = 24;
        children[0].span.lg = 8;

        api.zoneFields(0, 0).width.value = 24;

        // 24 left for two that stood 16 to 24 - two fifths and three fifths.
        expect(children.map((c) => c.span.lg)).toEqual([24, 10, 14]);
        expect(children.reduce((sum, c) => sum + c.span.lg, 0)).toBe(48);
    });

    it("never reduces another zone of the stack to nothing", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "media");

        api.zoneFields(0, 0).width.value = 48;

        expect(
            layout.value.zones[0].children[1].span.lg,
        ).toBeGreaterThanOrEqual(1);
    });

    /**
     * A zone of a stack shares its height by definition, so "the whole thing"
     * is a contradiction the row must not offer.
     */
    it("does not offer the full height as a share", () => {
        const { widthOptions, shareOptions } = make().api;

        expect(widthOptions.value.map((o) => o.value)).toContain(48);
        expect(shareOptions.value.map((o) => o.value)).not.toContain(48);
    });

    // ── Moving an existing zone into a stack ──────────────────────────────

    it("takes a zone off its row and puts it in a stack", () => {
        const { layout, content, api } = make();

        api.addZone("media");
        api.addZone("stack");
        api.addChild(1, "text");

        const movedId = layout.value.zones[0].id;
        api.zoneFields(0).alt.value = "Vue depuis la treille";

        expect(api.moveZoneIntoStack(0, 1, 0)).toBe(true);

        expect(layout.value.zones).toHaveLength(1);
        expect(layout.value.zones[0].children.map((c) => c.id)).toEqual([
            movedId,
            expect.any(String),
        ]);
        // Content is keyed by id and the id travelled, so nothing had to move.
        expect(content.value.zones[movedId].alt).toBe("Vue depuis la treille");
    });

    it("re-shares the height once a zone has moved in", () => {
        const { layout, api } = make();

        api.addZone("media");
        api.addZone("stack");
        api.addChild(1, "text");
        api.moveZoneIntoStack(0, 1, 1);

        expect(layout.value.zones[0].children.map((c) => c.span.lg)).toEqual([
            24, 24,
        ]);
    });

    /**
     * The splice that lifts the zone off the row shifts every index after it,
     * including the stack's own when the zone sat before it. Holding the stack
     * by reference is what makes that a non-issue - this pins it.
     */
    it("finds the right stack when the zone sat before it", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.addZone("media");
        api.addZone("stack");
        api.addChild(2, "text");

        const movedId = layout.value.zones[0].id;
        api.moveZoneIntoStack(0, 2, 0);

        expect(layout.value.zones.map((z) => z.type)).toEqual([
            "media",
            "stack",
        ]);
        expect(layout.value.zones[1].children[0].id).toBe(movedId);
    });

    it("refuses to put a stack inside a stack", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addZone("stack");

        expect(api.moveZoneIntoStack(0, 1, 0)).toBe(false);
        expect(layout.value.zones).toHaveLength(2);
    });

    it("refuses a move onto anything that is not a stack", () => {
        const { layout, api } = make();

        api.addZone("media");
        api.addZone("text");

        expect(api.moveZoneIntoStack(0, 1, 0)).toBe(false);
        expect(layout.value.zones).toHaveLength(2);
    });

    it("refuses a move into a stack that is already full", () => {
        const { layout, api } = make();

        api.addZone("media");
        api.addZone("stack");

        for (let index = 0; index < 6; index += 1) {
            api.addChild(1, "text");
        }

        expect(api.moveZoneIntoStack(0, 1, 0)).toBe(false);
        expect(layout.value.zones).toHaveLength(2);
    });

    // ── Taking a zone back out of a stack ─────────────────────────────────

    /**
     * Without this a stack is a trap: a zone built inside one could only leave
     * by being deleted, which drops what every language holds for it.
     */
    it("takes a zone out of a stack and puts it back on the row", () => {
        const { layout, content, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "text");

        const movedId = layout.value.zones[0].children[0].id;
        api.zoneFields(0, 0).alt.value = "Vue depuis la treille";

        expect(api.moveZoneOutOfStack(0, 0, 0)).toBe(true);

        expect(layout.value.zones.map((z) => z.type)).toEqual([
            "media",
            "stack",
        ]);
        expect(layout.value.zones[0].id).toBe(movedId);
        expect(layout.value.zones[1].children).toHaveLength(1);
        expect(content.value.zones[movedId].alt).toBe("Vue depuis la treille");
    });

    /**
     * The number meant a share of the height inside the stack and would mean a
     * width on the row. Carrying it over would reinterpret it silently - 36
     * going from "three quarters of the height" to "three quarters of the row",
     * a value nobody chose.
     */
    it("resets the span rather than reading a height share as a width", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "media");
        api.zoneFields(0, 0).width.value = 36;

        api.moveZoneOutOfStack(0, 0, 0);

        expect(layout.value.zones[0].span.lg).toBe(24);
    });

    it("re-shares what stays behind", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "media");
        api.addChild(0, "media");
        api.addChild(0, "media");

        api.moveZoneOutOfStack(0, 0, 0);

        expect(layout.value.zones[1].children.map((c) => c.span.lg)).toEqual([
            24, 24,
        ]);
    });

    it("ignores a move out of a zone that holds nothing", () => {
        const { api } = make();

        api.addZone("text");

        expect(api.moveZoneOutOfStack(0, 0, 0)).toBe(false);
    });

    it("stops adding to a stack at the cap", () => {
        const { layout, api } = make();

        api.addZone("stack");

        for (let index = 0; index < 10; index += 1) {
            api.addChild(0, "text");
        }

        expect(layout.value.zones[0].children).toHaveLength(6);
        expect(api.canAddChild(0)).toBe(false);
    });

    it("hands back no children for a zone that is not a stack", () => {
        const { api } = make();

        api.addZone("text");

        expect(api.childrenOf(0)).toEqual([]);
        expect(api.childrenOf(99)).toEqual([]);
    });
});

describe("placeZones", () => {
    const at = (...zones) =>
        placeZones(zones).map((place) => [place.row, place.column]);

    const widths = (...lg) =>
        at(
            ...lg.map((columns) => ({
                span: { base: 48, md: null, lg: columns },
            })),
        );

    it("lays a row out left to right", () => {
        expect(widths(24, 16, 8)).toEqual([
            [1, 1],
            [1, 25],
            [1, 41],
        ]);
    });

    it("starts a new row for a zone that does not fit in what is left", () => {
        expect(widths(32, 32)).toEqual([
            [1, 1],
            [2, 1],
        ]);
    });

    it("never backfills the gap a wrap leaves behind", () => {
        // A grid with `dense` would slot the 16 into the 16 columns left on the
        // first row. `.aurora-grid` does not set it, and neither does this.
        expect(widths(32, 32, 16)).toEqual([
            [1, 1],
            [2, 1],
            [2, 33],
        ]);
    });

    it("fills a row exactly without spilling to the next", () => {
        expect(widths(24, 24, 24)).toEqual([
            [1, 1],
            [1, 25],
            [2, 1],
        ]);
    });

    it("reads the large-screen width, not the one a phone gets", () => {
        expect(
            at(
                { span: { base: 48, md: null, lg: 24 } },
                { span: { base: 48, md: null, lg: 24 } },
            ),
        ).toEqual([
            [1, 1],
            [1, 25],
        ]);
    });

    it("treats a zone with no width of its own as full width", () => {
        expect(at({}, {})).toEqual([
            [1, 1],
            [2, 1],
        ]);
    });

    // ── The two arrangements the flow alone could not express ─────────────

    it("puts an offset zone at the right of an otherwise empty row", () => {
        expect(
            at({ span: { lg: 48 } }, { span: { lg: 24 }, offset: 24 }),
        ).toEqual([
            [1, 1],
            [2, 25],
        ]);
    });

    it("drops a zone below one it would have fitted beside", () => {
        expect(
            at({ span: { lg: 32 } }, { span: { lg: 16 }, newRow: true }),
        ).toEqual([
            [1, 1],
            [2, 1],
        ]);
    });

    /**
     * The case that made the row worth naming at all. Left to auto-placement
     * the second zone was put beside the first, because columns 33 to 48 were
     * free there and a grid places a definite column in the first row that can
     * take it - so the break did nothing.
     */
    it("holds a break even when the columns are free beside the neighbour", () => {
        expect(
            at(
                { span: { lg: 32 } },
                { span: { lg: 16 }, offset: 32, newRow: true },
            ),
        ).toEqual([
            [1, 1],
            [2, 33],
        ]);
    });

    it("goes on flowing from where an offset zone ends", () => {
        expect(
            at({ span: { lg: 12 }, offset: 12 }, { span: { lg: 24 } }),
        ).toEqual([
            [1, 13],
            [1, 25],
        ]);
    });

    it("ignores a break asked for by the first zone", () => {
        expect(
            at({ span: { lg: 24 }, newRow: true }, { span: { lg: 24 } }),
        ).toEqual([
            [1, 1],
            [1, 25],
        ]);
    });

    // Mirrors GridNormalizer::clampOffset. The canvas draws what a drag is
    // asking for before anything is saved, so it has to bound it the same way
    // the server will.
    it("never pushes a zone off the row it sits on", () => {
        expect(
            at(
                { span: { lg: 24 }, offset: 40 },
                { span: { lg: 48 }, offset: 12 },
            ),
        ).toEqual([
            [1, 25],
            [2, 1],
        ]);
    });
});

describe("resizeZoneFromLeft", () => {
    // The right edge is the fixed point - that is what makes this a resize and
    // not the push it replaced.
    it("moves the left edge and leaves the right one where it is", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 24;

        api.resizeZoneFromLeft(0, 12);

        expect(layout.value.zones[0].offset).toBe(12);
        expect(layout.value.zones[0].span.lg).toBe(12);
    });

    /**
     * Dragging past where the order puts the zone would ask it to start before
     * its neighbour ends, and the walk answers that by dropping it to the next
     * row - the zone would jump out from under the pointer.
     */
    it("will not take the edge left of where the order puts the zone", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.addZone("text");

        // The second zone starts at column 24; asked for 8, it stays at 24.
        api.resizeZoneFromLeft(1, 8);

        expect(layout.value.zones[1].offset).toBe(0);
        expect(layout.value.zones[1].span.lg).toBe(24);
    });

    // Reaching the flow position clears the offset rather than pinning the zone
    // to a column it would have taken anyway.
    it("gives the zone back to the flow when the edge reaches it", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 48;
        api.resizeZoneFromLeft(0, 24);
        expect(layout.value.zones[0].offset).toBe(24);

        api.resizeZoneFromLeft(0, 0);

        expect(layout.value.zones[0].offset).toBe(0);
        expect(layout.value.zones[0].span.lg).toBe(48);
    });

    it("leaves the zone at least one snap wide", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 24;

        api.resizeZoneFromLeft(0, 48);

        expect(layout.value.zones[0].span.lg).toBe(4);
        expect(layout.value.zones[0].offset).toBe(20);
    });
});

describe("addZoneAt", () => {
    it("puts a zone at the place asked for, on a row of its own", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.addZone("media");

        const at = api.addZoneAt("video", 1, { newRow: true });

        expect(at).toBe(1);
        expect(layout.value.zones[1].type).toBe("video");
        expect(layout.value.zones[1].newRow).toBe(true);
    });

    /**
     * Filling a hole: the zone takes the hole's width, rounded down to the step
     * so it cannot spill past it and push the neighbour onto another row.
     */
    it("fits a zone to the hole it was asked to fill", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.zoneFields(0).width.value = 32;

        api.addZoneAt("text", 1, { column: 32, width: 14 });

        expect(layout.value.zones[1].span.lg).toBe(12);
        // No offset: flowing after the zone beside it already starts it there,
        // and a zone that flows survives its neighbour changing width.
        expect(layout.value.zones[1].offset).toBe(0);
    });

    it("gives the new zone an entry in the open language", () => {
        const { layout, content, api } = make();

        api.addZoneAt("text", 0);

        expect(content.value.zones[layout.value.zones[0].id]).toEqual({
            blocks: [],
            alt: "",
            caption: "",
            url: "",
        });
    });
});

describe("moveZoneTo", () => {
    // Through the composable rather than the plan, because the point is that
    // the layout is written: the plan says what would happen, this does it.
    it("puts a zone where it was dropped and annotates only if it must", () => {
        const { layout, api } = make();

        api.addZone("text");
        api.addZone("text");
        api.zoneFields(0).width.value = 48;

        // The second zone, dropped after the first at the right of a new row.
        api.moveZoneTo(1, 1, 24, true);

        expect(layout.value.zones[1].offset).toBe(24);
        expect(layout.value.zones[1].newRow).toBe(true);
    });

    it("carries the zone's content with it, because the id travels", () => {
        const { layout, content, api } = make();

        api.addZone("text");
        api.addZone("media");
        api.zoneFields(1).alt.value = "Le chantier";

        const id = layout.value.zones[1].id;
        api.moveZoneTo(1, 0, 0, true);

        expect(layout.value.zones[0].id).toBe(id);
        expect(content.value.zones[id].alt).toBe("Le chantier");
    });

    it("declines a zone that is not there", () => {
        const { api } = make();

        expect(api.moveZoneTo(3, 0, 0, false)).toBe(false);
    });
});

describe("moveZoneOutOfStack", () => {
    /**
     * Dropped on the empty canvas rather than on a box, so the drop said a
     * column as well as a place in the order. Before this a slice could only
     * come out onto a box, which made the only way out of a stack an exchange
     * with something already on the page.
     */
    it("puts a zone leaving a stack where it was dropped", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "text");

        api.moveZoneOutOfStack(0, 0, 1, 24, true);

        expect(layout.value.zones[1].type).toBe("text");
        expect(layout.value.zones[1].offset).toBe(24);
        expect(layout.value.zones[1].newRow).toBe(true);
        // A share of a height is not a width, so it gets a fresh one.
        expect(layout.value.zones[1].span.lg).toBe(24);
    });

    it("leaves it flowing when a drop on a box says no column at all", () => {
        const { layout, api } = make();

        api.addZone("stack");
        api.addChild(0, "text");

        api.moveZoneOutOfStack(0, 0, 1);

        expect(layout.value.zones[1].offset).toBe(0);
        expect(layout.value.zones[1].newRow).toBe(false);
    });
});

describe("planMove", () => {
    const half = (id, extra = {}) => ({
        id,
        span: { base: 48, md: null, lg: 24 },
        offset: 0,
        newRow: false,
        ...extra,
    });

    /**
     * The point of preferring the flow: a zone dropped exactly where the order
     * already puts it keeps no annotation at all, so it goes on following its
     * neighbour when that neighbour changes width.
     */
    it("keeps a zone flowing when the drop is where the flow already puts it", () => {
        const zones = [half("a"), half("b"), half("c")];

        // Zone c dropped beside a, which is where the order would put it after
        // it moves in front of b.
        const plan = planMove(zones, 2, 1, 24, false);

        expect(plan.offset).toBe(0);
        expect(plan.newRow).toBe(false);
        expect(plan.place).toEqual({ row: 1, column: 25 });
    });

    it("pushes a zone rightwards when the drop is past what the flow gives", () => {
        const zones = [half("a"), half("b")];

        // Zone b dropped at the far right of the row below, on its own.
        const plan = planMove(zones, 1, 1, 24, true);

        expect(plan.newRow).toBe(true);
        expect(plan.place).toEqual({ row: 2, column: 25 });
    });

    it("takes a zone from the end of the order to the front of it", () => {
        const zones = [half("a"), half("b"), half("c")];
        const plan = planMove(zones, 2, 0, 0, false);

        expect(plan.at).toBe(0);
        expect(plan.place).toEqual({ row: 1, column: 1 });
    });

    /** Pure: what it is asked about is left exactly as it was found. */
    it("changes nothing about the zones it is handed", () => {
        const zones = [half("a"), half("b")];
        const before = JSON.stringify(zones);

        planMove(zones, 1, 0, 24, true);

        expect(JSON.stringify(zones)).toBe(before);
    });

    it("has no answer for a zone that is not there", () => {
        expect(planMove([half("a")], 4, 0, 0, false)).toBeNull();
    });
});
