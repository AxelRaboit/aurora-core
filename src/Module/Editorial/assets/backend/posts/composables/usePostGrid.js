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

/** What a zone may be inside a stack, where nesting stops. */
export const LEAF_ZONE_TYPES = ["text", "media", "post", "video"];

/** Mirrors GridNormalizer::ZONE_TYPES — a stack is top level only. */
export const ZONE_TYPES = [...LEAF_ZONE_TYPES, "stack"];

/**
 * How many zones a stack may hold. Mirrors GridNormalizer::MAX_STACK_CHILDREN:
 * a stack splits one cell in two or three, and six zones sharing a row's height
 * are six slivers.
 */
const MAX_STACK_CHILDREN = 6;

/**
 * Mirrors GridNormalizer::RATIOS — the shape a media zone is cropped to, and
 * the only vertical control the grid offers. `natural` is what every zone
 * starts as, because the default has to be what is already published.
 */
export const ZONE_RATIOS = ["natural", "16x9", "4x3", "1x1", "3x4", "fill"];

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
 * The width a zone has on a large screen, following the stylesheet's own
 * fallback chain: an unset breakpoint inherits the one below it.
 */
export function largeSpan(zone) {
    const columns =
        zone?.span?.lg ?? zone?.span?.md ?? zone?.span?.base ?? COLUMNS;

    return Math.min(COLUMNS, Math.max(1, columns));
}

/**
 * An offset the row can actually hold — never more than what is left once the
 * zone has taken its own width.
 *
 * Mirrors GridNormalizer::clampOffset. Written here as well as at the write
 * boundary because this one runs on the layout as it is being edited, before
 * anything has been saved: the canvas has to draw what a half-finished drag is
 * asking for, and it has to draw it the way the server will store it.
 */
export function clampOffset(offset, zone) {
    return Math.max(
        0,
        Math.min(COLUMNS - largeSpan(zone), Math.round(Number(offset) || 0)),
    );
}

/**
 * Where each zone lands: its row and its first column, both 1-based.
 *
 * Mirrors GridNormalizer::place. The canvas emits what this returns as
 * `--row-base` and `--start-base`, so the picture in the panel and the
 * published page are the same arithmetic rather than two guesses that happen to
 * agree.
 *
 * The row is worked out here rather than left to auto-placement, which was the
 * first attempt and was not enough: a grid puts an item with a definite column
 * in the first row where those columns are free, so a zone asked to start a new
 * row — whose columns happened to be free beside its neighbour — was placed
 * there and the break did nothing.
 *
 * Widths come from `span.lg`: the large-screen arrangement is the one an author
 * sets, and every zone is full width below that.
 *
 * @param {Array<object>} zones
 * @return {Array<{row: number, column: number}>} one per zone, in order
 */
export function placeZones(zones) {
    let row = 1;
    let used = 0;

    return zones.map((zone) => {
        const span = largeSpan(zone);
        const offset = clampOffset(zone?.offset, zone);

        if (zone?.newRow && used > 0) {
            row += 1;
            used = 0;
        }

        let start;

        if (offset > 0) {
            // A row fills from the left, so what is free on it is always its
            // tail: an asked-for column below the mark is taken, and the zone
            // goes to the next row where the same column is free by definition.
            if (offset < used) {
                row += 1;
                used = 0;
            }

            start = offset;
        } else {
            if (used + span > COLUMNS) {
                row += 1;
                used = 0;
            }

            start = used;
        }

        used = start + span;

        return { row, column: start + 1 };
    });
}

/**
 * What dropping a zone somewhere would come to, without doing it.
 *
 * Pure, and shared by the canvas and the composable for one reason: the ghost
 * the author sees under the pointer has to be the layout they get when they let
 * go. Computing the preview one way and the move another is how a drop lands
 * somewhere other than where it was aimed.
 *
 * `target` is a place in the order with the moved zone already taken out of it,
 * and `column` the zero-based column it was dropped on. `newRow` says the drop
 * fell between rows rather than beside a zone.
 *
 * **The flow is preferred over an annotation.** If the zone lands on the asked
 * column with no offset at all — dropped right after its neighbour, say — that
 * is what is kept, because a layout that flows survives its neighbours changing
 * width and one pinned to a column does not.
 *
 * @return {{at: number, offset: number, newRow: boolean, place: {row: number, column: number}}|null}
 */
export function planMove(zones, index, target, column, newRow) {
    const list = [...zones];
    const [moving] = list.splice(index, 1);

    if (undefined === moving) {
        return null;
    }

    const at = Math.min(Math.max(0, target), list.length);
    const planned = { ...moving, offset: 0, newRow: Boolean(newRow) };
    list.splice(at, 0, planned);

    let place = placeZones(list)[at];

    if (place.column - 1 !== column) {
        planned.offset = clampOffset(column, planned);
        place = placeZones(list)[at];
    }

    return { at, offset: planned.offset, newRow: planned.newRow, place };
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
        // No gap to its left and no break before it: a new zone joins the flow
        // where the last one left off, which is what "add a zone" has always
        // meant. Both are annotations an author reaches for, never defaults.
        offset: 0,
        newRow: false,
        ratio: "natural",
        mediaId: null,
        media: null,
        mediaUrl: "",
        postId: null,
        // Empty on every zone, filled only by a stack — the same reason every
        // other key is always present: switching a type back and forth in the
        // editor must not lose what was picked.
        children: [],
    };
}

/**
 * Give every zone of a stack an equal share, summing to 48.
 *
 * `flex-grow` is relative, so equal values would already split evenly whatever
 * they are. Making them sum to 48 is for the author, not the browser: it is
 * what lets the fraction row say the truth — with three children at 16, "1/3"
 * really is a third of the height.
 */
function shareEvenly(children) {
    if (0 === children.length) {
        return;
    }

    const each = Math.max(1, Math.round(COLUMNS / children.length));

    for (const child of children) {
        child.span.lg = each;
    }
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

    const typeOptions = computed(() => typeChoices(ZONE_TYPES));

    // A zone inside a stack cannot become a stack: depth stops at one, and the
    // normaliser would drop it rather than nest it.
    const leafTypeOptions = computed(() => typeChoices(LEAF_ZONE_TYPES));

    function typeChoices(types) {
        return types.map((type) => ({
            value: type,
            label: t(`backend.posts.grid.zone_types.${type}`),
        }));
    }

    const ratioOptions = computed(() =>
        ZONE_RATIOS.map((ratio) => ({
            value: ratio,
            label: t(`backend.posts.grid.ratios.${ratio}`),
        })),
    );

    /**
     * The same fractions, less "the whole thing".
     *
     * A zone of a stack shares its height with the others by definition, so
     * offering it the full height offers a contradiction: the rebalance would
     * have to leave the others a sliver, and the button would promise something
     * the page cannot show. Three quarters is the most one zone may claim.
     */
    const shareOptions = computed(() =>
        widthOptions.value.filter((option) => option.value !== COLUMNS),
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

    /**
     * How far a zone may be pushed from the left of its row, in the same
     * fractions its width is set in — so "1/2 wide, pushed by 1/2" reads as one
     * thought rather than as two unrelated numbers.
     *
     * "None" leads, and is what every zone has: pushing one is the exception,
     * and the row has to offer a way back to the flow.
     */
    const offsetOptions = computed(() => [
        { value: 0, label: t("backend.posts.grid.offsets.none") },
        ...WIDTH_FRACTIONS.filter((fraction) => fraction.columns < COLUMNS).map(
            (fraction) => ({
                value: fraction.columns,
                label: fraction.label,
                title: t(`backend.posts.grid.fractions.${fraction.name}`),
            }),
        ),
    ]);

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

    /**
     * The zones a stack holds, or an empty list for anything else — so a caller
     * can ask any zone without first checking what it is.
     */
    function childrenOf(index) {
        return layout.value.zones[index]?.children ?? [];
    }

    function canAddChild(index) {
        return childrenOf(index).length < MAX_STACK_CHILDREN;
    }

    /**
     * Adding re-shares the height evenly across the stack. An author who has
     * set proportions deliberately will set them again; one who has not gets
     * halves, then thirds, which is what "add another" is expected to mean.
     */
    function addChild(index, type) {
        const zone = layout.value.zones[index];

        if (undefined === zone || !canAddChild(index)) {
            return;
        }

        const child = newZone(type);
        zone.children.push(child);
        shareEvenly(zone.children);
        content.value.zones[child.id] = newZoneContent();
    }

    function removeChild(index, childIndex) {
        const zone = layout.value.zones[index];
        const [removed] = zone?.children?.splice(childIndex, 1) ?? [];

        if (removed) {
            delete content.value.zones[removed.id];
            shareEvenly(zone.children);
        }
    }

    function moveChild(index, childIndex, offset) {
        const list = layout.value.zones[index]?.children ?? [];
        const target = childIndex + offset;

        if (target < 0 || target >= list.length) {
            return;
        }

        [list[childIndex], list[target]] = [list[target], list[childIndex]];
    }

    /**
     * What a zone of a stack really gets, as a percentage.
     *
     * The spans are grow factors, so what counts is each one against their
     * total, not against 48. They sum to 48 when the editor set them and can
     * stop doing so the moment an author picks fractions by hand — at which
     * point "2/3" on both zones would be two lies. This is the number that
     * cannot lie, and the panel shows it beside the buttons.
     */
    /**
     * Take a zone off its row and put it inside a stack.
     *
     * The other half of "add a zone to a stack": that one makes a new zone,
     * this one moves the zone already laid out — which is the thing an author
     * has no other way to do, short of deleting and rebuilding it and losing
     * what every other language holds for it.
     *
     * The stack is held by reference rather than by index, because the splice
     * that removes the zone shifts every index after it — including, half the
     * time, the stack's own.
     *
     * Content is not touched: it is keyed by zone id, and the id travels.
     *
     * @return {boolean} whether the move happened, so a caller can leave the
     *                   selection alone when it did not.
     */
    function moveZoneIntoStack(fromIndex, stackIndex, atIndex) {
        const list = layout.value.zones;
        const stack = list[stackIndex];
        const moving = list[fromIndex];

        if (
            undefined === stack ||
            undefined === moving ||
            fromIndex === stackIndex
        ) {
            return false;
        }

        // Depth stops at one, so a stack cannot go inside a stack — the
        // normaliser would drop it on the way out, which is a zone silently
        // lost rather than a move refused.
        if ("stack" !== stack.type || "stack" === moving.type) {
            return false;
        }

        if (stack.children.length >= MAX_STACK_CHILDREN) {
            return false;
        }

        list.splice(fromIndex, 1);
        // A row's annotations mean nothing in a column. Cleared here rather
        // than left for the server, so what the canvas draws the moment the
        // zone lands is what a save would produce.
        moving.offset = 0;
        moving.newRow = false;
        stack.children.splice(atIndex, 0, moving);
        shareEvenly(stack.children);

        return true;
    }

    /**
     * Take a zone out of a stack and put it back on the row.
     *
     * The mirror of moving one in, and the reason a stack is not a trap: a zone
     * built inside one could otherwise only leave by being deleted, which drops
     * what every language holds for it.
     *
     * **Its span is reset rather than carried over.** Inside a stack the number
     * was a share of the height; on a row the same number is a width. Keeping
     * it would silently reinterpret 36 from "three quarters of the height" to
     * "three quarters of the row" — a value nobody chose, arrived at by the
     * field meaning two things. Half is what a new zone gets.
     *
     * @return {boolean} whether the move happened.
     */
    function moveZoneOutOfStack(stackIndex, childIndex, atIndex) {
        const stack = layout.value.zones[stackIndex];
        const moving = stack?.children?.[childIndex];

        if (undefined === moving) {
            return false;
        }

        stack.children.splice(childIndex, 1);
        shareEvenly(stack.children);

        moving.span.lg = 24;
        moving.offset = 0;
        moving.newRow = false;
        layout.value.zones.splice(atIndex, 0, moving);

        return true;
    }

    /**
     * Hand the rest of the height back to the other zones of a stack.
     *
     * Shares are grow factors, so only their ratio matters — but an author
     * reading "2/3" means two thirds of the stack, not two parts against
     * whatever the neighbour happens to hold. Keeping the total at 48 is what
     * makes the fraction row honest.
     *
     * Split in proportion to what the others already had, so a stack an author
     * has tuned keeps its shape when one zone changes. Each keeps at least one
     * unit: a zone reduced to nothing would vanish from the page with no
     * control left to bring it back.
     */
    function rebalance(index, changedIndex) {
        const list = childrenOf(index);
        const others = list.filter((_, i) => i !== changedIndex);

        if (0 === others.length) {
            return;
        }

        const left = Math.max(
            others.length,
            COLUMNS - list[changedIndex].span.lg,
        );
        const before = others.reduce((sum, child) => sum + child.span.lg, 0);

        let given = 0;

        others.forEach((child, i) => {
            const share =
                0 === before
                    ? Math.round(left / others.length)
                    : Math.round((child.span.lg / before) * left);

            // The last one takes what rounding left over, so the total is 48
            // exactly rather than 47 or 49.
            child.span.lg =
                i === others.length - 1
                    ? Math.max(1, left - given)
                    : Math.max(1, share);

            given += child.span.lg;
        });
    }

    function childShare(index, childIndex) {
        const list = childrenOf(index);
        const total = list.reduce(
            (sum, child) => sum + (child.span?.lg ?? 0),
            0,
        );

        if (0 === total) {
            return 0;
        }

        return Math.round(((list[childIndex]?.span?.lg ?? 0) / total) * 100);
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
     * Put a zone where it was dropped, rather than where the order happened to
     * leave it.
     *
     * The gesture the canvas offers alongside the swap, and the one that makes
     * a zone feel movable: drop it in empty space and it goes there — into that
     * place in the order, at that column, on a row of its own if it was dropped
     * between two. Before this, moving a zone rightwards meant dragging a
     * three-pixel handle, which is a resize gesture wearing a move's clothes.
     *
     * Content is not touched: it is keyed by zone id, and the id travels.
     *
     * @return {boolean} whether the move happened.
     */
    function moveZoneTo(index, target, column, newRow) {
        const list = layout.value.zones;
        const plan = planMove(list, index, target, column, newRow);

        if (null === plan) {
            return false;
        }

        const [moving] = list.splice(index, 1);
        moving.offset = plan.offset;
        moving.newRow = plan.newRow;
        list.splice(plan.at, 0, moving);

        return true;
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

    function zoneFields(index, childIndex = null) {
        const key = null === childIndex ? `${index}` : `${index}:${childIndex}`;

        if (!zoneFieldsCache.has(key)) {
            // Two levels and no more, which is what lets a path be two numbers
            // rather than a list to walk. The normaliser refuses a stack inside
            // a stack, so there is no third.
            const zone = () =>
                null === childIndex
                    ? layout.value.zones[index]
                    : layout.value.zones[index]?.children?.[childIndex];

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

            zoneFieldsCache.set(key, {
                // Shared — the arrangement.
                type: shared("type"),
                postId: shared("postId"),
                ratio: shared("ratio"),
                mediaUrl: shared("mediaUrl"),
                // The width control drives the large-screen span only. Below
                // that a zone stays full width, which is what the stored
                // `base` says and what reads best on a phone.
                // Both are top-level only: inside a stack the axis of flow is
                // vertical, so there is no row to start or to sit at the end
                // of. The panel does not show them there, and the normaliser
                // would zero them anyway.
                offset: writable(
                    () => zone()?.offset ?? 0,
                    (value) => {
                        // Zero is exempt from the snap, which has the step as
                        // its floor: rounding it up would leave no way back to
                        // the flow, and "no gap" is the answer most zones want.
                        zone().offset = clampOffset(
                            0 === Number(value)
                                ? 0
                                : clampToSnap(value, snap.value),
                            zone(),
                        );
                    },
                ),
                newRow: writable(
                    () => zone()?.newRow ?? false,
                    (value) => {
                        zone().newRow = Boolean(value);
                    },
                ),
                width: writable(
                    () => zone()?.span?.lg ?? COLUMNS,
                    (value) => {
                        zone().span.lg = clampToSnap(value, snap.value);

                        // A wider zone leaves less room to its left. Without
                        // this a zone pushed to the right and then widened to
                        // the full row would keep an offset the row cannot
                        // hold — and the server, which clamps on the way in,
                        // would give back a layout the editor never showed.
                        if (null === childIndex) {
                            zone().offset = clampOffset(zone().offset, zone());
                        }

                        // Inside a stack the shares are relative to each other,
                        // so setting one without touching the rest gives an
                        // author who picked "2/3" something else — 24 and 32
                        // are 43% and 57%, not a third and two thirds. Giving
                        // the remainder back to the others is what makes the
                        // button mean what it says.
                        if (null !== childIndex) {
                            rebalance(index, childIndex);
                        }
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

        return zoneFieldsCache.get(key);
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
        leafTypeOptions,
        widthOptions,
        offsetOptions,
        shareOptions,
        ratioOptions,
        childrenOf,
        canAddChild,
        addChild,
        removeChild,
        moveChild,
        moveZoneIntoStack,
        moveZoneOutOfStack,
        childShare,
        addZone,
        removeZone,
        moveZone,
        moveZoneTo,
        swapZones,
        zoneFields,
        widthLabel,
    };
}
