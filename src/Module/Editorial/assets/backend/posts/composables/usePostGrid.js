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
const COLUMNS = 48;
const MAX_ZONES = 60;

/** Mirrors GridNormalizer::SNAPS — four is twelfths, the usual way to talk about a layout. */
export const SNAPS = [4, 2, 1];

export const ZONE_TYPES = ["text", "media", "post", "video"];

function writable(get, set) {
    return computed({ get, set });
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
     * Up/down rather than drag: zones flow, so moving one is reordering it,
     * and the rest of the backend reorders this way. A drag target on a grid
     * whose rows re-flow as you drag is a harder thing to aim at than it looks.
     */
    function moveZone(index, offset) {
        const target = index + offset;

        if (target < 0 || target >= layout.value.zones.length) {
            return;
        }

        const list = layout.value.zones;
        [list[index], list[target]] = [list[target], list[index]];
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
        addZone,
        removeZone,
        moveZone,
        zoneFields,
        widthLabel,
    };
}
