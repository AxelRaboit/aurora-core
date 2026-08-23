<script setup>
/**
 * The content grid, drawn as something you can take hold of.
 *
 * This is a picture of the **arrangement**, not of the content. Each zone is a
 * box carrying its type and its width; the server preview behind the button
 * stays the authority on what a zone actually renders as. Drawing content here
 * would mean a second renderer beside the Twig one, which is precisely how the
 * `twoColumn` block came to write a shape its own renderer could not read.
 *
 * The geometry is not an imitation. `app.css` is the project's single CSS entry
 * and the backend layout loads it, so `.aurora-grid` is the very stylesheet the
 * public page uses - 48 tracks, `column-gap: 0`, gutters from the items' own
 * padding.
 *
 * Two deliberate departures from the public rendering, both because this is a
 * scale model rather than a preview:
 *
 * - **Placement goes into the `-base` properties, not `-lg`.** The real chain
 *   only applies the large-screen values above a 1024px *viewport*, and this
 *   panel is often read in a narrower window. Left to the media queries the
 *   canvas would show every zone full width and the handles would look broken.
 * - **Boxes share one height.** On the page a zone is as tall as its content.
 *   Here even heights are what makes the rows legible as rows.
 *
 * Presentation only. Three composables hold what this used to: the geometry, the
 * two edge handles, and everything a drag can mean. What is left is the markup,
 * the icons, and one UI-only ref for the type picker.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, Film, GripVertical, Image, Layers, Newspaper, Plus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { COLUMNS, zoneImage, zoneLabel } from "../composables/usePostGrid.js";
import { usePostGridPlacement } from "../composables/usePostGridPlacement.js";
import { usePostGridResize } from "../composables/usePostGridResize.js";
import { usePostGridDrop } from "../composables/usePostGridDrop.js";

const props = defineProps({
    /** The arrangement, in order. Read-only here - every change is emitted. */
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

const emit = defineEmits(["update:selectedIndex", "resize", "resizeStart", "add", "addAt", "fillGap", "swap", "move", "moveInto", "moveOut"]);

const { t } = useI18n();

const ZONE_ICONS = {
    text: FileText,
    media: Image,
    post: Newspaper,
    video: Film,
    stack: Layers,
};

const gridEl = ref(null);

const zones = computed(() => props.zones);
const snap = computed(() => props.snap);

const { widthOf, startOf, styleOf, rowGaps, rowStrips } = usePostGridPlacement(
    zones,
    snap,
);

const {
    dragging,
    onPointerDown,
    onPointerMove,
    onPointerUp,
    onKeydown,
    onStartKeydown,
} = usePostGridResize({ gridEl, snap, widthOf, startOf, emit });

const {
    draggingFrom,
    dropTarget,
    dropSlice,
    ghostStyle,
    onDragStart,
    onDragOver,
    onDrop,
    onDragEnd,
    onSliceDragStart,
    onSliceOver,
    onSliceDrop,
    onGridOver,
    onGridDrop,
} = usePostGridDrop({ zones, gridEl, emit });

const labelOf = (zone) => zoneLabel(zone, props.postOptions, t);
const imageOf = zoneImage;

/**
 * Which strip or hole is being filled, if any, so it can offer the types.
 *
 * UI-only, which is why it stays here: nothing about it is saved, and no other
 * component could want it. One picker open at a time - opening a second closes
 * the first, because two open pickers are two half-finished sentences.
 */
const picking = ref(null);

function openPicker(id) {
    picking.value = picking.value === id ? null : id;
}

/**
 * The types a strip or a hole may offer, which is not all of them.
 *
 * A stack is left out on purpose. It holds zones rather than content, so one
 * created from a hole would be an empty frame the author then has to go and fill
 * from the panel - the opposite of the one click this is for. It stays on the
 * row of buttons below the canvas, where adding an empty thing is what the
 * gesture already means.
 */
const fillTypes = computed(() =>
    props.typeOptions.filter((option) => "stack" !== option.value),
);
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
                 measured against is its border box - padding on that element
                 would offset every column by it, and a handle near an edge
                 would answer a column or so off. -->
            <div v-else class="p-2">
                <!-- A quarter of the page's gutter. The real one is a flat 1rem
                 whatever the container is wide, so at panel width it would eat
                 most of a narrow zone - scaling it down keeps the proportions
                 honest rather than breaking them.

                 `gap-y-2` is 0.5rem, which is what two neighbours on one row
                 already show between them: each carries the gutter as padding,
                 so the space between them is two of them. Matching the row axis
                 to it makes the zones sit on an even lattice instead of rows
                 that touch while columns breathe.

                 The row axis is the only one that may take a gap. `column-gap`
                 on 48 tracks is 47 gutters, not one - `AuroraGridGutterTest`
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
                             grid item, whose padding box includes the gutters -
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
                                 it was unreadable - the column count in
                                 `text-muted` disappeared entirely against a
                                 photo, which is the one part of the box that
                                 has to stay legible. Only where there is an
                                 image: on a plain box the plate would be a
                                 rectangle around nothing. -->
                            <!-- A stack draws what it holds, at the shares it
                                 holds it at: the same `flex-grow` the page uses,
                                 so the picture is right rather than merely
                                 suggestive. Icons only - a slice of an 80px box
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
                         by an edge - an edge that moved the whole box would be
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

                    <!-- One per hole in a row. Quiet at rest and lit on
                         hover, like the strips between rows - the same idea on
                         the other axis, so the two read as one system rather
                         than two inventions. -->
                    <div
                        v-for="gap in rowGaps"
                        :key="`gap-${gap.row}-${gap.start}`"
                        :style="{
                            '--span-base': gap.width,
                            '--row-base': gap.row * 2,
                            '--start-base': gap.start + 1,
                        }"
                    >
                        <div
                            class="flex h-20 flex-wrap items-center justify-center gap-1 rounded-md border border-dashed border-line transition"
                            :class="
                                picking === `gap-${gap.row}-${gap.start}`
                                    ? 'border-accent bg-accent/5 opacity-100'
                                    : 'text-muted opacity-40 hover:border-accent hover:bg-accent/10 hover:text-accent hover:opacity-100'
                            "
                            v-on:keydown.esc="picking = null"
                        >
                            <template v-if="picking === `gap-${gap.row}-${gap.start}`">
                                <AppIconButton
                                    v-for="option in fillTypes"
                                    :key="option.value"
                                    color="default"
                                    :title="option.label"
                                    v-on:click="emit('fillGap', option.value, gap.target, gap.start, gap.width); picking = null"
                                >
                                    <component :is="ZONE_ICONS[option.value]" class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                            </template>
                            <button
                                v-else
                                type="button"
                                class="flex h-full w-full items-center justify-center rounded-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
                                :title="t('backend.posts.grid.fill_gap')"
                                :disabled="!canAdd"
                                v-on:click="openPicker(`gap-${gap.row}-${gap.start}`)"
                            >
                                <Plus class="w-4 h-4" :stroke-width="2" />
                                <span class="sr-only">{{ t("backend.posts.grid.fill_gap") }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- One per gap between rows, plus one above the first.
                         Faint rather than hidden: a control nobody can see is a
                         control nobody uses, and one drawn at full strength
                         between every row would read as a ladder the zones sit
                         on. Hovering brings it up to something you would click. -->
                    <div
                        v-for="strip in rowStrips"
                        :key="`strip-${strip.track}`"
                        :style="{ '--span-base': COLUMNS, '--row-base': strip.track }"
                        v-on:keydown.esc="picking = null"
                    >
                        <!-- The strip grows into a picker rather than opening
                             one beside it: four pixels is not room for five
                             buttons, and a popover would have to be positioned
                             against a grid track that moves as the rows do. -->
                        <div
                            v-if="picking === `strip-${strip.track}`"
                            class="flex flex-wrap items-center justify-center gap-1 rounded border border-dashed border-accent bg-accent/5 py-1"
                        >
                            <AppIconButton
                                v-for="option in fillTypes"
                                :key="option.value"
                                color="default"
                                :title="option.label"
                                v-on:click="emit('addAt', option.value, strip.target, strip.newRow); picking = null"
                            >
                                <component :is="ZONE_ICONS[option.value]" class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                        <button
                            v-else
                            type="button"
                            class="flex h-4 w-full items-center justify-center gap-2 rounded text-muted opacity-30 transition hover:bg-accent/10 hover:text-accent hover:opacity-100 focus-visible:opacity-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent disabled:opacity-20"
                            :title="t('backend.posts.grid.add_row')"
                            :disabled="!canAdd"
                            v-on:click="openPicker(`strip-${strip.track}`)"
                        >
                            <span class="h-px flex-1 bg-current" />
                            <Plus class="w-3 h-3 shrink-0" :stroke-width="2" />
                            <span class="h-px flex-1 bg-current" />
                            <span class="sr-only">{{ t("backend.posts.grid.add_row") }}</span>
                        </button>
                    </div>

                    <!-- Where the zone being dragged would land, drawn with the
                         very properties the boxes use - so what is promised
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
                 still and is deliberately not that - it would claim real
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
