<script setup>
/**
 * The content grid, drawn as something you can take hold of.
 *
 * This is a picture of the **arrangement**, not of the content. Each zone is a
 * box carrying its type and its width; the server preview below the panel stays
 * the authority on what a zone actually renders as. Drawing content here would
 * mean a second renderer beside the Twig one, which is precisely how the
 * `twoColumn` block came to write a shape its own renderer could not read.
 *
 * The geometry is not an imitation. `app.css` is the project's single CSS entry
 * and the backend layout loads it, so `.aurora-grid` is the very stylesheet the
 * public page uses — 48 tracks, `column-gap: 0`, gutters from the items' own
 * padding. Two zones that will not share a row wrap here for the same reason
 * they wrap there, with nothing written to make that happen.
 *
 * Two deliberate departures from the public rendering, both because this is a
 * scale model rather than a preview:
 *
 * - **Widths go into `--span-base`, not `--span-lg`.** The real chain only
 *   applies `--span-lg` above a 1024px *viewport*, and this panel is often
 *   read in a narrower window. Left to the media queries the canvas would show
 *   every zone full width and the handles would look broken. What the author
 *   edits is the large-screen arrangement, so the canvas shows that one at any
 *   panel width.
 * - **Boxes share one height.** On the page a zone is as tall as its content.
 *   Here even heights are what makes the rows legible as rows.
 *
 * Presentation only: the canvas emits the width it was dragged to and never
 * clamps it. Rounding to the snap and bounding to 1..48 belong to usePostGrid,
 * which already does both for the fraction row — two clamps would be two rules
 * to keep in agreement.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, Film, GripVertical, Image, Layers, Newspaper, Plus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import { COLUMNS, largeSpan, placeZones, planMove } from "../composables/usePostGrid.js";

const props = defineProps({
    /** The arrangement, in order. Read-only here — resizing is emitted. */
    zones: { type: Array, required: true },
    /** The snap in force, for how far an arrow key moves a handle. */
    snap: { type: Number, default: 4 },
    /** Which zone is under the author's attention, or null. */
    selectedIndex: { type: Number, default: null },
    /** Publications the `post` zones may name, so a box can show its title. */
    postOptions: { type: Array, default: () => [] },
    /** The kinds of zone that can be added, as `{ value, label }`. */
    typeOptions: { type: Array, default: () => [] },
    /** False at the zone cap, which disables the add buttons rather than hiding them. */
    canAdd: { type: Boolean, default: true },
});

const emit = defineEmits(["update:selectedIndex", "resize", "resizeStart", "add", "addAt", "swap", "move", "moveInto", "moveOut"]);

const { t } = useI18n();

const ZONE_ICONS = {
    text: FileText,
    media: Image,
    post: Newspaper,
    video: Film,
    stack: Layers,
};

const gridEl = ref(null);
/** The zone being dragged, so a stray pointermove on another handle is ignored. */
const dragging = ref(null);

const placements = computed(() => placeZones(props.zones));

function widthOf(index) {
    return props.zones[index]?.span?.lg ?? COLUMNS;
}

/** The column a zone begins on, zero-based — what its left edge is holding. */
function startOf(index) {
    return (placements.value[index]?.column ?? 1) - 1;
}

/**
 * The item's own placement, as the custom properties `.aurora-grid` reads.
 *
 * All three go into the `-base` slot for the same reason the width does: the
 * real chain only applies the large-screen values above a 1024px viewport, and
 * this panel is usually read in a narrower window. What an author edits is the
 * large-screen arrangement, so the canvas draws that one at any panel width.
 */
function styleOf(index) {
    const at = placements.value[index];

    return {
        "--span-base": widthOf(index),
        "--row-base": at ? at.row * 2 : "auto",
        "--start-base": at?.column ?? "auto",
    };
}

/**
 * The strips between the rows, each one an offer to open a row there.
 *
 * The canvas lays zones on every *other* grid track — `row * 2` above — so the
 * odd tracks in between are free for these. It costs one line and keeps the
 * strips inside the same grid, which is what makes them land exactly in the gaps
 * rather than being positioned to look as though they do. The page is untouched:
 * it emits the walk's own row numbers, and only this drawing doubles them.
 *
 * A strip carries the place in the order a zone added there would take, and
 * whether it needs a break to hold its row: the first strip sits above
 * everything, where there is no row to break out of.
 *
 * There is no strip for an *empty* row, because there is no such thing to make.
 * A row is what zones put on it — it appears with the first one and goes when
 * the last leaves, so a row can never be left behind empty and never needs
 * deleting.
 */
const rowStrips = computed(() => {
    const strips = [{ track: 1, target: 0, newRow: false }];
    let row = 0;
    let seen = 0;

    placements.value.forEach((place) => {
        if (place.row !== row) {
            row = place.row;
        }

        seen += 1;
        strips[row] = { track: row * 2 + 1, target: seen, newRow: true };
    });

    return strips.filter(Boolean);
});

/**
 * What a box says about itself. A linked publication shows its title and a
 * media zone its image — both are values already held in the editor's state,
 * not markup rebuilt from the content. A text zone shows its type and stops
 * there, which is where the line sits.
 */
function labelOf(zone) {
    if ("post" === zone.type) {
        const linked = props.postOptions.find((post) => post.id === zone.postId);

        return linked?.title ?? t("backend.posts.grid.zone_types.post");
    }

    return t(`backend.posts.grid.zone_types.${zone.type}`);
}

function imageOf(zone) {
    if ("media" !== zone.type) {
        return null;
    }

    // Same order the renderer uses: a picked document first, the address only
    // when there is none. A canvas showing the other one would be a picture of
    // a page nobody is going to get.
    return zone.media?.url ?? (zone.mediaUrl || null);
}

/**
 * The width the pointer is asking for: the column it sits over, less the column
 * the zone starts on.
 *
 * `start` is read fresh on every move rather than frozen at pointerdown. A zone
 * dragged wider than the room left on its row wraps to a row of its own, and
 * its start column becomes zero — recomputing keeps the handle under the
 * pointer through that jump. It cannot oscillate: once wrapped, the zone only
 * comes back when it fits again, and a width that fits cannot re-wrap.
 */
function resizeFromPointer(index, clientX) {
    const rect = gridEl.value?.getBoundingClientRect();

    if (!rect?.width) {
        return;
    }

    const column = ((clientX - rect.left) / rect.width) * COLUMNS;

    // `column` on a placement is 1-based, like `grid-column`; the pointer's is
    // measured from zero. Subtracting the two without this reads every width a
    // column too wide.
    emit("resize", index, column - (placements.value[index].column - 1));
}

/**
 * The column the pointer is over, taken as where the zone's left edge should be.
 *
 * Both edges resize, and the right one stays put while this moves — which is
 * what makes it a resize rather than the push it used to be. Moving a zone is a
 * separate gesture now: take hold of it in the middle and drop it where it goes.
 *
 * Measured from the grid's left edge rather than from the zone, because that is
 * where a row begins and the column is what the answer is expressed in. The
 * floor — a left edge cannot go past where the order puts the zone — lives in
 * usePostGrid, beside the width clamp it has to agree with.
 */
function startFromPointer(index, clientX) {
    const rect = gridEl.value?.getBoundingClientRect();

    if (!rect?.width) {
        return;
    }

    emit("resizeStart", index, ((clientX - rect.left) / rect.width) * COLUMNS);
}

/** Which handle a pointer gesture is holding — `null`, or `resize` / `start`. */
const draggingKind = ref(null);

function onPointerDown(index, event, kind = "resize") {
    // Stops the drag from selecting the labels it passes over.
    event.preventDefault();
    dragging.value = index;
    draggingKind.value = kind;
    // Optional because jsdom has no pointer capture, and a canvas that throws
    // in its own test is a canvas nobody tests.
    event.currentTarget.setPointerCapture?.(event.pointerId);
    emit("update:selectedIndex", index);
}

function onPointerMove(index, event) {
    if (dragging.value !== index) {
        return;
    }

    if ("start" === draggingKind.value) {
        startFromPointer(index, event.clientX);

        return;
    }

    resizeFromPointer(index, event.clientX);
}

function onPointerUp(event) {
    dragging.value = null;
    draggingKind.value = null;

    if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
        event.currentTarget.releasePointerCapture(event.pointerId);
    }
}

/**
 * Reordering by dropping one zone onto another, which exchanges their places.
 *
 * Native drag and drop rather than pointer events with a movement threshold:
 * the browser already knows the difference between a click and a drag, draws
 * the ghost, and leaves the box's own click free to go on selecting.
 *
 * It is a swap and not an insertion on purpose. Dropping *between* two zones
 * says more, and the gap it aims at moves while it is being aimed at, because
 * the rows re-flow as widths shift. A box holds still.
 *
 * No keyboard equivalent here, and none is owed: the up/down buttons on the
 * selected zone reorder without a pointer and are the path that has to work.
 */
const draggingFrom = ref(null);
const dropTarget = ref(null);

function onDragStart(index, event) {
    draggingFrom.value = index;
    // Firefox starts no drag at all unless something is written here.
    event.dataTransfer?.setData("text/plain", String(index));

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = "move";
    }
}

function onDragOver(index, event) {
    // A box covers part of the canvas, and the canvas behind it means "move the
    // zone here". Over a box the gesture means something else — exchange — so
    // the event stops here rather than being claimed by both. Before the stop,
    // hovering your own box cancelled the dragover through the grid and then
    // dropped to nothing, which is a cursor promising a move that never came.
    event.stopPropagation();
    dropPlan.value = null;

    // A slice dragged out of a stack lands here too — the box says "on the row,
    // at this place", which is the only thing leaving a stack can mean.
    if (null !== draggingSlice.value) {
        event.preventDefault();
        dropTarget.value = index;

        return;
    }

    if (null === draggingFrom.value || draggingFrom.value === index) {
        return;
    }

    // A dragover that is not cancelled means "no drop here", so this is what
    // makes the box a target at all rather than a nicety.
    event.preventDefault();
    dropTarget.value = index;
}

/**
 * Dropping onto a slice of a stack rather than onto the stack itself.
 *
 * Two intents need two targets, or the author cannot tell what a drop will do.
 * A stack's slices are already drawn, so they are the target that says "inside,
 * here"; the rest of the box keeps the meaning every other zone has, which is
 * exchange. Aiming at a slice is aiming at a rectangle that holds still — the
 * same reason dropping *between* zones was refused on the row axis.
 */
const dropSlice = ref(null);

/**
 * Whether this slice may take what is being dragged.
 *
 * One predicate for both handlers. A browser will not fire `drop` on a target
 * whose `dragover` was not cancelled, so asking twice looks redundant — but
 * that is the browser enforcing our rule for us, and a rule enforced somewhere
 * we do not control is a rule that holds until it does not.
 *
 * A stack refuses a stack because depth stops at one: the normaliser would drop
 * it on the way out, which loses a zone silently rather than refusing a move.
 */
function sliceAccepts(stackIndex) {
    const from = draggingFrom.value;

    return (
        null !== from &&
        from !== stackIndex &&
        "stack" !== props.zones[from]?.type
    );
}

/**
 * The slice being dragged out, as `stackIndex:childIndex`, or null.
 *
 * Held apart from `draggingFrom` because the two answer different questions —
 * one names a zone on the row, the other a zone inside a stack — and a single
 * field would have to be read twice to tell which.
 */
const draggingSlice = ref(null);

function onSliceDragStart(stackIndex, childIndex, event) {
    // Stops the stack's own box from starting a drag of the whole stack.
    event.stopPropagation();
    draggingSlice.value = { stackIndex, childIndex };
    event.dataTransfer?.setData("text/plain", `${stackIndex}:${childIndex}`);

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = "move";
    }
}

function onSliceOver(stackIndex, atIndex, event) {
    if (!sliceAccepts(stackIndex)) {
        return;
    }

    event.preventDefault();
    // Stops the stack's own box from also claiming the drop as a swap.
    event.stopPropagation();
    dropSlice.value = `${stackIndex}:${atIndex}`;
    dropTarget.value = null;
}

function onSliceDrop(stackIndex, atIndex, event) {
    if (!sliceAccepts(stackIndex)) {
        // Left to bubble on purpose: the box behind takes it as the exchange
        // every drop means by default, so the gesture does something rather
        // than nothing.
        return;
    }

    event.stopPropagation();
    emit("moveInto", draggingFrom.value, stackIndex, atIndex);
    onDragEnd();
}

function onDrop(index, event) {
    // Stops the grid behind from also taking it as a move to empty space. A
    // drop on a box means exchange, and only that.
    event?.stopPropagation();

    if (null !== draggingSlice.value) {
        const { stackIndex, childIndex } = draggingSlice.value;
        emit("moveOut", stackIndex, childIndex, index);
        onDragEnd();

        return;
    }

    if (null !== draggingFrom.value && draggingFrom.value !== index) {
        emit("swap", draggingFrom.value, index);
        // The zone the author was holding is now here, and it is the one they
        // were working on.
        emit("update:selectedIndex", index);
    }

    onDragEnd();
}

function onDragEnd() {
    draggingFrom.value = null;
    draggingSlice.value = null;
    dropTarget.value = null;
    dropSlice.value = null;
    dropPlan.value = null;
}

/**
 * Dropping a zone in the empty part of the canvas, which moves it there.
 *
 * The gesture this panel was missing. A drop on another zone exchanges the two
 * and always did; a drop on nothing had no meaning at all, so the only way to
 * push a zone rightwards was the three-pixel handle on its left edge — a resize
 * gesture standing in for a move, which is what made this awkward enough to
 * complain about.
 *
 * Where it lands is read off the pointer rather than off a list of slots. A slot
 * would have to be drawn, and the ones worth drawing move while they are being
 * aimed at: the rows re-flow as widths shift. The pointer is over one column of
 * 48 and either inside a row or between two, and those two facts are the whole
 * of the answer.
 */
const dropPlan = ref(null);

/**
 * The column under the pointer, and where the zone would sit in the order.
 *
 * A zone counts as "before the drop" when it is on an earlier row, or on the
 * same one and more than half passed — the midpoint rather than the edge, so
 * the answer changes where the pointer visibly crosses a box rather than at the
 * moment it leaves one.
 *
 * `newRow` is true when the pointer is in none of the rows: the gap between two,
 * or the space below the last. That is the only place a break can be asked for
 * with a drop, and it reads as one — you are putting the zone between things.
 */
function dropAt(event) {
    const rect = gridEl.value?.getBoundingClientRect();

    if (!rect?.width || null === draggingFrom.value) {
        return null;
    }

    const column = Math.max(
        0,
        Math.min(
            COLUMNS - 1,
            Math.floor(((event.clientX - rect.left) / rect.width) * COLUMNS),
        ),
    );

    let target = 0;
    let newRow = true;

    // Only the zone boxes: the grid also holds the between-row strips and the
    // drop ghost, and counting either as a zone would put the drop somewhere
    // other than where it was aimed.
    gridEl.value.querySelectorAll(":scope > [data-zone]").forEach((child) => {
        const index = Number(child.dataset.zone);

        if (index === draggingFrom.value) {
            return;
        }

        const box = child.getBoundingClientRect();

        if (box.bottom < event.clientY) {
            target += 1;

            return;
        }

        if (box.top > event.clientY) {
            return;
        }

        // Inside this row, so the drop joins it rather than opening a new one.
        newRow = false;

        if (box.left + box.width / 2 < event.clientX) {
            target += 1;
        }
    });

    return { target, column, newRow };
}

function onGridOver(event) {
    if (null === draggingFrom.value) {
        return;
    }

    const at = dropAt(event);

    if (null === at) {
        return;
    }

    event.preventDefault();
    dropPlan.value = { ...at, plan: planMove(props.zones, draggingFrom.value, at.target, at.column, at.newRow) };
}

function onGridDrop(event) {
    const at = dropAt(event);

    if (null !== at && null !== draggingFrom.value) {
        emit("move", draggingFrom.value, at.target, at.column, at.newRow);
        // The zone the author was holding has moved, and it is the one they
        // were working on — its new index is where it was put.
        emit("update:selectedIndex", at.target);
    }

    onDragEnd();
}

/** The ghost's own placement, in the same properties every box uses. */
const ghostStyle = computed(() => {
    const plan = dropPlan.value?.plan;

    if (!plan || null === draggingFrom.value) {
        return null;
    }

    return {
        "--span-base": largeSpan(props.zones[draggingFrom.value]),
        "--row-base": plan.place.row * 2,
        "--start-base": plan.place.column,
    };
});

/**
 * The handle answers to the keyboard as well as the pointer.
 *
 * This whole rework started from someone saying that aiming a slider is hard;
 * a canvas that only takes a drag would have restated that problem in a worse
 * form. Arrows move by the snap, Home and End go to the extremes — which is
 * what `role="slider"` promises on the element, and the promise should hold.
 */
function onKeydown(index, event) {
    const width = widthOf(index);

    const asked = {
        ArrowLeft: width - props.snap,
        ArrowRight: width + props.snap,
        ArrowDown: width - props.snap,
        ArrowUp: width + props.snap,
        Home: props.snap,
        End: COLUMNS,
    }[event.key];

    if (undefined === asked) {
        return;
    }

    event.preventDefault();
    emit("resize", index, asked);
}

/**
 * The left edge answers to the keyboard too, for the same reason the right one
 * does: a gesture that only exists as a drag is one some people cannot make.
 * Home takes the edge as far left as the order allows, End as far right as the
 * zone's own minimum width leaves — both worked out by usePostGrid, so the
 * extremes here are simply out of range and get clamped.
 */
function onStartKeydown(index, event) {
    const start = startOf(index);

    const asked = {
        ArrowLeft: start - props.snap,
        ArrowRight: start + props.snap,
        ArrowDown: start - props.snap,
        ArrowUp: start + props.snap,
        Home: 0,
        End: COLUMNS,
    }[event.key];

    if (undefined === asked) {
        return;
    }

    event.preventDefault();
    emit("resizeStart", index, asked);
}
</script>

<template>
    <div class="space-y-1.5">
        <p class="text-xs font-medium text-secondary uppercase tracking-wide">
            {{ t("backend.posts.grid.canvas") }}
        </p>

        <div class="rounded-lg border border-line bg-surface-2/30">
            <p v-if="!zones.length" class="p-4 text-sm text-muted">
                {{ t("backend.posts.grid.canvas_empty") }}
            </p>

            <!-- The padding lives here and never on the grid itself. The 48
                 tracks span the grid's *content* box, while the rect a drag is
                 measured against is its border box — padding on that element
                 would offset every column by it, and a handle near an edge
                 would answer a column or so off. -->
            <div v-else class="p-2">
                <!-- A quarter of the page's gutter. The real one is a flat 1rem
                 whatever the container is wide, so at panel width it would eat
                 most of a narrow zone — scaling it down keeps the proportions
                 honest rather than breaking them.

                 `gap-y-2` is 0.5rem, which is what two neighbours on one row
                 already show between them: each carries the gutter as padding,
                 so the space between them is two of them. Matching the row axis
                 to it makes the zones sit on an even lattice instead of rows
                 that touch while columns breathe.

                 The row axis is the only one that may take a gap. `column-gap`
                 on 48 tracks is 47 gutters, not one — `AuroraGridGutterTest`
                 reads this file and fails the build for `gap-*` or `gap-x-*`,
                 while allowing `gap-y-*` for exactly this reason. -->
                <div
                    ref="gridEl"
                    class="aurora-grid gap-y-2"
                    style="--aurora-gutter: 0.25rem"
                    v-on:dragover="onGridOver"
                    v-on:drop="onGridDrop"
                >
                    <div
                        v-for="(zone, index) in zones"
                        :key="zone.id"
                        class="relative"
                        :data-zone="index"
                        :style="styleOf(index)"
                    >
                        <button
                            type="button"
                            draggable="true"
                            class="relative flex h-20 w-full cursor-grab flex-col items-center justify-center gap-1 overflow-hidden rounded-md border px-1 text-center transition-colors active:cursor-grabbing"
                            :class="[
                                dropTarget === index
                                    ? 'border-accent border-dashed bg-accent/20'
                                    : selectedIndex === index
                                        ? 'border-accent bg-accent/10'
                                        : 'border-line bg-surface-1 hover:border-secondary',
                                draggingFrom === index ? 'opacity-40' : '',
                            ]"
                            :title="labelOf(zone)"
                            :aria-pressed="selectedIndex === index"
                            v-on:click="emit('update:selectedIndex', index)"
                            v-on:dragstart="onDragStart(index, $event)"
                            v-on:dragover="onDragOver(index, $event)"
                            v-on:dragleave="dropTarget = null"
                            v-on:drop="onDrop(index, $event)"
                            v-on:dragend="onDragEnd"
                        >
                            <!-- The box above is `relative` for this one element.
                             Without it the nearest positioned ancestor is the
                             grid item, whose padding box includes the gutters —
                             so the picture spilled a gutter either side of its
                             own border and the zone read as wider than it is,
                             which also made its row look tighter than the rest.
                             `overflow-hidden` on the box does not catch that: a
                             block does not clip an absolute descendant whose
                             containing block is one of its own ancestors. -->
                            <img
                                v-if="imageOf(zone)"
                                :src="imageOf(zone)"
                                alt=""
                                class="absolute inset-0 h-full w-full rounded-md object-cover opacity-70"
                            >
                            <!-- On a zone carrying a picture the label needs a
                                 plate of its own. Laid straight over the image
                                 it was unreadable — the column count in
                                 `text-muted` disappeared entirely against a
                                 photo, which is the one part of the box that
                                 has to stay legible. Only where there is an
                                 image: on a plain box the plate would be a
                                 rectangle around nothing. -->
                            <!-- A stack draws what it holds, at the shares it
                                 holds it at: the same `flex-grow` the page uses,
                                 so the picture is right rather than merely
                                 suggestive. Icons only — a slice of an 80px box
                                 has no room for a word, and the panel below
                                 names every zone anyway.

                                 Its own children carry no handle: a zone's share
                                 here is a height, and the canvas only ever
                                 resized widths. That is set in the panel. -->
                            <div
                                v-if="'stack' === zone.type && zone.children?.length"
                                class="relative flex h-full w-full flex-col gap-1 py-1"
                            >
                                <div class="flex min-h-0 flex-1 flex-col gap-0.5">
                                    <div
                                        v-for="(child, childIndex) in zone.children"
                                        :key="child.id"
                                        class="flex min-h-0 items-center justify-center rounded-sm border bg-surface-1"
                                        :class="
                                            dropSlice === `${index}:${childIndex}`
                                                ? 'border-dashed border-accent bg-accent/20'
                                                : 'border-line'
                                        "
                                        :style="{ flexGrow: child.span?.lg ?? 1, flexBasis: 0 }"
                                        :title="t(`backend.posts.grid.zone_types.${child.type}`)"
                                        draggable="true"
                                        v-on:dragstart="onSliceDragStart(index, childIndex, $event)"
                                        v-on:dragover="onSliceOver(index, childIndex, $event)"
                                        v-on:dragleave="dropSlice = null"
                                        v-on:drop="onSliceDrop(index, childIndex, $event)"
                                    >
                                        <component
                                            :is="ZONE_ICONS[child.type]"
                                            class="w-3 h-3 shrink-0 text-secondary"
                                            :stroke-width="2"
                                        />
                                    </div>
                                </div>
                                <span class="shrink-0 text-[10px] text-muted tabular-nums">
                                    {{ widthOf(index) }}/{{ COLUMNS }} · {{ zone.children.length }}
                                </span>
                            </div>

                            <div
                                v-else
                                class="relative flex flex-col items-center gap-1"
                                :class="imageOf(zone) ? 'rounded-md bg-surface/90 px-2 py-1' : ''"
                            >
                                <component
                                    :is="ZONE_ICONS[zone.type]"
                                    class="w-4 h-4 shrink-0 text-secondary"
                                    :stroke-width="2"
                                />
                                <!-- Hidden under about a sixth, where it would be
                                     one clipped letter pretending to be a word. -->
                                <span
                                    v-if="widthOf(index) >= 8"
                                    class="line-clamp-2 text-xs text-primary"
                                >{{ labelOf(zone) }}</span>
                                <span class="text-[10px] text-muted tabular-nums">
                                    {{ widthOf(index) }}/{{ COLUMNS }}<template v-if="'stack' === zone.type"> · 0</template>
                                </span>
                            </div>
                        </button>

                        <!-- The other edge, and the same gesture: what moves
                         is where the zone starts, and the right edge stays put.
                         A zone is moved by taking hold of it in the middle, not
                         by an edge — an edge that moved the whole box would be
                         two controls wearing one shape. -->
                        <button
                            type="button"
                            role="slider"
                            data-handle="start"
                            aria-orientation="horizontal"
                            :aria-label="t('backend.posts.grid.resize_start_zone', { zone: labelOf(zone) })"
                            :aria-valuemin="0"
                            :aria-valuemax="COLUMNS - snap"
                            :aria-valuenow="startOf(index)"
                            :aria-valuetext="t('backend.posts.grid.start_label', { columns: startOf(index) + 1, total: COLUMNS })"
                            class="absolute inset-y-0 left-1 flex w-3 cursor-col-resize touch-none items-center justify-center rounded-l-md text-muted hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
                            v-on:pointerdown="onPointerDown(index, $event, 'start')"
                            v-on:pointermove="onPointerMove(index, $event)"
                            v-on:pointerup="onPointerUp"
                            v-on:pointercancel="onPointerUp"
                            v-on:keydown="onStartKeydown(index, $event)"
                        >
                            <GripVertical class="w-3 h-3" :stroke-width="2" />
                        </button>

                        <!-- Sits inside the item's padding, so it lands on the box
                         edge rather than in the gutter between two zones. -->
                        <button
                            type="button"
                            role="slider"
                            data-handle="width"
                            aria-orientation="horizontal"
                            :aria-label="t('backend.posts.grid.resize_zone', { zone: labelOf(zone) })"
                            :aria-valuemin="snap"
                            :aria-valuemax="COLUMNS"
                            :aria-valuenow="widthOf(index)"
                            :aria-valuetext="t('backend.posts.grid.width_label', { columns: widthOf(index), total: COLUMNS })"
                            class="absolute inset-y-0 right-1 flex w-3 cursor-col-resize touch-none items-center justify-center rounded-r-md text-muted hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
                            v-on:pointerdown="onPointerDown(index, $event)"
                            v-on:pointermove="onPointerMove(index, $event)"
                            v-on:pointerup="onPointerUp"
                            v-on:pointercancel="onPointerUp"
                            v-on:keydown="onKeydown(index, $event)"
                        >
                            <GripVertical class="w-3 h-3" :stroke-width="2" />
                        </button>
                    </div>

                    <!-- One per gap between rows, plus one above the first.
                         Faint rather than hidden: a control nobody can see is a
                         control nobody uses, and one drawn at full strength
                         between every row would read as a ladder the zones sit
                         on. Hovering brings it up to something you would click. -->
                    <button
                        v-for="strip in rowStrips"
                        :key="`strip-${strip.track}`"
                        type="button"
                        class="group flex h-4 items-center justify-center gap-2 rounded text-muted opacity-30 transition hover:bg-accent/10 hover:text-accent hover:opacity-100 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent disabled:opacity-20"
                        :style="{ '--span-base': COLUMNS, '--row-base': strip.track }"
                        :title="t('backend.posts.grid.add_row')"
                        :disabled="!canAdd"
                        v-on:click="emit('addAt', strip.target, strip.newRow)"
                    >
                        <span class="h-px flex-1 bg-current" />
                        <Plus class="w-3 h-3 shrink-0" :stroke-width="2" />
                        <span class="h-px flex-1 bg-current" />
                        <span class="sr-only">{{ t("backend.posts.grid.add_row") }}</span>
                    </button>

                    <!-- Where the zone being dragged would land, drawn with the
                         very properties the boxes use — so what is promised
                         under the pointer is what the drop produces, rather than
                         a second guess at it. Last in the DOM and marked, so the
                         walk above skips it when working out the order. -->
                    <div
                        v-if="ghostStyle"
                        data-ghost="true"
                        class="pointer-events-none"
                        :style="ghostStyle"
                    >
                        <div class="h-20 rounded-md border-2 border-dashed border-accent bg-accent/10" />
                    </div>
                </div>
            </div>

            <!-- Adding lives in the canvas rather than at the foot of the
                 panel, so one frame answers the whole question of what the
                 page is made of: the zones, their widths, and how to get
                 another. A `+` cell inside the grid itself would read better
                 still and is deliberately not that — it would claim real
                 columns, so it would change where the rows wrap, and a canvas
                 that wraps differently from the page is worth less than a
                 slightly plainer one that does not. -->
            <div class="flex flex-wrap gap-2 border-t border-line p-2">
                <AppButton
                    v-for="option in typeOptions"
                    :key="option.value"
                    variant="secondary"
                    size="sm"
                    type="button"
                    :disabled="!canAdd"
                    v-on:click="emit('add', option.value)"
                >
                    <component :is="ZONE_ICONS[option.value]" class="w-4 h-4" :stroke-width="2" />
                    {{ option.label }}
                </AppButton>
            </div>
        </div>

        <p v-if="zones.length" class="text-xs text-muted">
            {{ t("backend.posts.grid.move_hint") }}
            {{ zones.length > 1 ? t("backend.posts.grid.swap_hint") : "" }}
            {{ t("backend.posts.grid.canvas_hint") }}
        </p>
    </div>
</template>
