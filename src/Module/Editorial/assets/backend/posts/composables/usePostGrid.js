import { computed } from "vue";
import { useI18n } from "vue-i18n";

/**
 * Drives the content-grid panel of the post editor.
 *
 * Holds no state of its own, and reads from two places for the same reason
 * usePostBanner does: the **arrangement** lives on the post and is shared by
 * every language, the **content** of each zone lives in the current
 * translation. Switching locale swaps what fills the zones and leaves the
 * layout standing.
 *
 * Every field is a writable computed rather than being bound straight in the
 * template: `v-model` on a prop's property is a mutation ESLint rightly
 * refuses, and the write belongs next to the mapping it goes through.
 */
export const COLUMNS = 48;
const MAX_ZONES = 60;

/** Mirrors GridNormalizer::SNAPS — four is twelfths, the usual way to talk about a layout. */
export const SNAPS = [4, 2, 1];

export const ZONE_TYPES = ["text", "media", "post", "video"];

/**
 * Mirrors GridNormalizer::RATIOS — the shape a media zone is cropped to, and
 * the only vertical control the grid offers. `natural` is what every zone
 * starts as, because the default has to be what is already published.
 */
export const ZONE_RATIOS = ["natural", "16x9", "4x3", "1x1", "3x4"];

/**
 * The widths an author actually draws with, named the way they think of them.
 *
 * "24 of 48 columns" is a coordinate; "a half" is a thought. Every one of these
 * lands on a whole number of columns because 48 is 4 × 12 — and, as it happens,
 * every one is a multiple of four, so they survive `clampToSnap` at any step.
 * The snap and the fractions never disagree.
 *
 * Sixths (8 and 40) are arithmetically just as clean and are deliberately left
 * out: nobody lays a page out in fifths of anything, and this row is meant to be
 * easy to aim at. They stay reachable through the precise slider.
 *
 * Ordered by width, so the arrow that widens a zone here is the same direction
 * as the handle that widens it on the canvas.
 */
export const WIDTH_FRACTIONS = [
    { columns: 12, label: "1/4", name: "quarter" },
    { columns: 16, label: "1/3", name: "third" },
    { columns: 24, label: "1/2", name: "half" },
    { columns: 32, label: "2/3", name: "two_thirds" },
    { columns: 36, label: "3/4", name: "three_quarters" },
    { columns: 48, label: "1/1", name: "full" },
];

function writable(get, set) {
    return computed({ get, set });
}

/**
 * Where each zone lands once the grid has wrapped: which row, and which column
 * it starts on.
 *
 * This is arithmetic rather than measurement because it can be. `.aurora-grid`
 * places items with the default `grid-auto-flow: row` — no `dense` — so an item
 * that does not fit in what is left of a row starts the next one at column
 * zero, and nothing ever backfills the gap behind it. That is exactly the loop
 * below, so the answer matches what the browser will do without reading a
 * single rect.
 *
 * Widths are read from `span.lg`: the large-screen arrangement is the one an
 * author sets, and `span.base` is 48 for every zone.
 *
 * @param {Array<{span?: {lg?: number}}>} zones
 * @return {Array<{row: number, start: number}>} one entry per zone, in order
 */
export function placeZones(zones) {
    let row = 0;
    let used = 0;

    return zones.map((zone) => {
        const span = Math.min(COLUMNS, Math.max(1, zone?.span?.lg ?? COLUMNS));

        if (used + span > COLUMNS) {
            row += 1;
            used = 0;
        }

        const start = used;
        used += span;

        return { row, start };
    });
}

/**
 * A fresh id for a zone. Random rather than a counter: a counter reuses the id
 * of a zone just removed, and the next one created would silently inherit its
 * content in every other language.
 */
function newZoneId() {
    if ("function" === typeof globalThis.crypto?.randomUUID) {
        return globalThis.crypto.randomUUID().replaceAll("-", "").slice(0, 24);
    }

    return Array.from({ length: 24 }, () =>
        Math.floor(Math.random() * 36).toString(36),
    ).join("");
}

function newZone(type) {
    return {
        id: newZoneId(),
        type,
        // Full width on a phone, half on a large screen. A zone alone on its
        // row still fills it, because the grid has nothing to put beside it.
        span: { base: COLUMNS, md: null, lg: 24 },
        ratio: "natural",
        mediaId: null,
        media: null,
        postId: null,
    };
}

/** The four per-language fields, as a translation starts with them. */
function newZoneContent() {
    return { blocks: [], alt: "", caption: "", url: "" };
}

export function usePostGrid(layout, content) {
    const { t } = useI18n();

    const zones = computed(() => layout.value.zones);
    const canAddZone = computed(() => layout.value.zones.length < MAX_ZONES);

    const enabled = writable(
        () => layout.value.enabled,
        (value) => {
            layout.value.enabled = value;
        },
    );

    const snap = writable(
        () => layout.value.snap,
        (value) => {
            layout.value.snap = value;
        },
    );

    const snapOptions = computed(() =>
        SNAPS.map((step) => ({
            value: step,
            label: t(`backend.posts.grid.snaps.${step}`),
        })),
    );

    const typeOptions = computed(() =>
        ZONE_TYPES.map((type) => ({
            value: type,
            label: t(`backend.posts.grid.zone_types.${type}`),
        })),
    );

    const ratioOptions = computed(() =>
        ZONE_RATIOS.map((ratio) => ({
            value: ratio,
            label: t(`backend.posts.grid.ratios.${ratio}`),
        })),
    );

    // The figures are the same in every language, so they stay literal; the
    // spelt-out name rides along as the tooltip.
    const widthOptions = computed(() =>
        WIDTH_FRACTIONS.map((fraction) => ({
            value: fraction.columns,
            label: fraction.label,
            title: t(`backend.posts.grid.fractions.${fraction.name}`),
        })),
    );

    function addZone(type) {
        if (!canAddZone.value) {
            return;
        }

        const zone = newZone(type);
        layout.value.zones.push(zone);
        // Only this language's entry. The others gain theirs when the server
        // normalises their content against the layout — an empty zone is what
        // an untranslated one means.
        content.value.zones[zone.id] = newZoneContent();
    }

    function removeZone(index) {
        const [removed] = layout.value.zones.splice(index, 1);

        if (removed) {
            delete content.value.zones[removed.id];
        }
    }

    /**
     * Exchange two zones' places in the order.
     *
     * A swap rather than a move-to-position, because that is the gesture the
     * canvas offers: a zone is dropped **on** another one. Dropping *between*
     * two zones would be the more expressive move and is much harder to aim
     * at — the rows re-flow as the widths shift, so the gap being aimed for
     * moves while it is being aimed at. A box is a target that stays still.
     *
     * Content follows without being touched: it is keyed by zone id, and the
     * ids travel with the zones.
     */
    function swapZones(a, b) {
        const list = layout.value.zones;

        if (a === b || undefined === list[a] || undefined === list[b]) {
            return;
        }

        [list[a], list[b]] = [list[b], list[a]];
    }

    /**
     * Reordering by one step, which is what the up/down buttons do — and the
     * path that works without a pointer, so it stays whatever the canvas
     * offers.
     */
    function moveZone(index, offset) {
        const target = index + offset;

        if (target < 0 || target >= layout.value.zones.length) {
            return;
        }

        swapZones(index, target);
    }

    // Built once per index and cached: the template calls zoneFields(index) on
    // every render, and handing back fresh computeds each time would throw
    // away their caching for nothing.
    const zoneFieldsCache = new Map();

    function zoneFields(index) {
        if (!zoneFieldsCache.has(index)) {
            const zone = () => layout.value.zones[index];

            // What this zone holds, in whichever language is open. Created on
            // demand: a zone added in one locale reaches the others with no
            // entry of its own until someone types in them.
            const held = () => {
                const id = zone()?.id;

                if (undefined === id) {
                    return {};
                }

                content.value.zones[id] ??= newZoneContent();

                return content.value.zones[id];
            };

            const shared = (key) =>
                writable(
                    () => zone()?.[key],
                    (value) => {
                        zone()[key] = value;
                    },
                );

            const localised = (key) =>
                writable(
                    () => held()[key] ?? "",
                    (value) => {
                        held()[key] = value;
                    },
                );

            zoneFieldsCache.set(index, {
                // Shared — the arrangement.
                type: shared("type"),
                postId: shared("postId"),
                ratio: shared("ratio"),
                // The width control drives the large-screen span only. Below
                // that a zone stays full width, which is what the stored
                // `base` says and what reads best on a phone.
                width: writable(
                    () => zone()?.span?.lg ?? COLUMNS,
                    (value) => {
                        zone().span.lg = clampToSnap(value, snap.value);
                    },
                ),
                media: writable(
                    () => ({
                        id: zone()?.mediaId ?? null,
                        url: zone()?.media?.url ?? null,
                    }),
                    (picked) => {
                        zone().mediaId = picked?.id ?? null;
                        // Keep the url the picker handed back so the preview
                        // survives until the next save; the server re-resolves
                        // it from the id on the way out.
                        zone().media = picked?.id
                            ? { url: picked.url ?? null }
                            : null;
                    },
                ),
                // Per language — what fills it.
                blocks: localised("blocks"),
                alt: localised("alt"),
                caption: localised("caption"),
                url: localised("url"),
            });
        }

        return zoneFieldsCache.get(index);
    }

    /**
     * A width the author can actually reach with the current step. Changing the
     * step does not rewrite the zones already placed — a layout should not
     * shift because someone went looking for finer control — but every new
     * width lands on it.
     */
    function clampToSnap(value, step) {
        const columns = Math.round(Number(value) / step) * step;

        return Math.max(step, Math.min(COLUMNS, columns));
    }

    /** How wide a zone reads, as the fraction an author thinks in. */
    function widthLabel(index) {
        const columns = zoneFields(index).width.value;

        return t("backend.posts.grid.width_label", {
            columns,
            total: COLUMNS,
        });
    }

    return {
        COLUMNS,
        zones,
        canAddZone,
        enabled,
        snap,
        snapOptions,
        typeOptions,
        widthOptions,
        ratioOptions,
        addZone,
        removeZone,
        moveZone,
        swapZones,
        zoneFields,
        widthLabel,
    };
}
