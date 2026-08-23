<script setup>
/**
 * The month grid.
 *
 * Draws what `monthGrid.js` works out and decides nothing about dates itself:
 * which days show, which events touch which week, and how two overlapping runs
 * avoid each other are all settled there, where they are tested without a
 * component mounted.
 *
 * Two shapes of event, because they answer different questions. A run of days is
 * a bar across the cells it covers - one bar, not one chip per day, which is what
 * separates a calendar from a list grouped by date. A meeting inside one day is a
 * dot and a time, because a cell with four filled chips is a wall of colour.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { MAX_LANES, layOutWeek, sameDay, timedEventsOn } from "../composables/monthGrid.js";

const props = defineProps({
    /** The 42 cells from `monthGrid`. */
    cells: { type: Array, required: true },
    /** Serialised events overlapping the grid's window. */
    events: { type: Array, required: true },
});

defineEmits(["open-event", "add-on"]);

const { t, d } = useI18n();

const today = new Date();

/** Monday first, from the locale rather than written out. */
const weekdays = computed(() =>
    Array.from({ length: 7 }, (_, index) =>
        d(new Date(2026, 5, 1 + index), { weekday: "short" }),
    ),
);

/**
 * The six weeks, each with its own cells and its own laid-out bars.
 *
 * Computed as one structure rather than per cell: lanes are a property of the
 * week, and a cell cannot know which lane it is on without seeing its neighbours.
 */
const weeks = computed(() =>
    Array.from({ length: props.cells.length / 7 }, (_, row) => {
        const cells = props.cells.slice(row * 7, row * 7 + 7);
        const { bars, hiddenPerDay } = layOutWeek(cells[0].date, props.events);

        return { row, cells, bars, hiddenPerDay };
    }),
);

function isToday(date) {
    return sameDay(date, today);
}

function timedOn(date) {
    return timedEventsOn(date, props.events);
}

function timeOf(event) {
    return d(new Date(event.startAt), { hour: "2-digit", minute: "2-digit" });
}
</script>

<template>
    <div class="bg-surface border border-line rounded-xl overflow-hidden">
        <div class="grid grid-cols-7 border-b border-line">
            <span
                v-for="day in weekdays"
                :key="day"
                class="px-2 py-1.5 text-2xs font-semibold uppercase tracking-wider text-muted"
            >
                {{ day }}
            </span>
        </div>

        <div v-for="week in weeks" :key="week.row" class="relative border-b border-line last:border-b-0">
            <!-- The day cells. Their padding-top leaves room for the bars, which
                 are positioned over the row rather than inside a cell: a bar
                 belongs to the week, and nesting it in the first day's cell would
                 clip it at that cell's edge. -->
            <div class="grid grid-cols-7">
                <div
                    v-for="cell in week.cells"
                    :key="cell.key"
                    class="min-h-[6.5rem] border-r border-line last:border-r-0 px-1.5 pb-1.5 flex flex-col gap-1"
                    :class="cell.inMonth ? '' : 'bg-surface-2/40'"
                    :style="{ paddingTop: `${1.75 + Math.min(MAX_LANES, week.bars.length) * 1.25}rem` }"
                >
                    <button
                        v-for="event in timedOn(cell.date)"
                        :key="event.id"
                        type="button"
                        class="flex items-center gap-1.5 text-left text-2xs rounded px-0.5 py-px hover:bg-surface-2 transition-colors min-w-0"
                        v-on:click="$emit('open-event', event)"
                    >
                        <span
                            class="w-1.5 h-1.5 rounded-full shrink-0"
                            :style="{ backgroundColor: `var(--chart-cat-${event.colourSlot})` }"
                        />
                        <span class="text-muted tabular-nums shrink-0">{{ timeOf(event) }}</span>
                        <span class="text-secondary truncate">{{ event.title }}</span>
                    </button>

                    <span
                        v-if="week.hiddenPerDay[week.cells.indexOf(cell)] > 0"
                        class="text-2xs text-muted"
                    >
                        {{ t("backend.plannings.more", { count: week.hiddenPerDay[week.cells.indexOf(cell)] }) }}
                    </span>
                </div>
            </div>

            <!-- The day numbers, over the cells, so a bar can sit under them
                 without pushing them down. -->
            <div class="absolute inset-x-0 top-0 grid grid-cols-7 pointer-events-none">
                <span
                    v-for="cell in week.cells"
                    :key="`n-${cell.key}`"
                    class="px-1.5 pt-1 text-xs tabular-nums"
                    :class="cell.inMonth ? 'text-secondary' : 'text-muted'"
                >
                    <span
                        v-if="isToday(cell.date)"
                        class="inline-block rounded-full bg-accent-600 px-1.5 font-semibold text-white"
                    >{{ cell.dayOfMonth }}</span>
                    <span v-else>{{ cell.dayOfMonth }}</span>
                </span>
            </div>

            <!-- The runs. One absolutely positioned bar per lane, spanning the
                 days it covers; a cut end is square so the reader can see it goes
                 on into the next row. -->
            <div class="absolute inset-x-0 top-6 pointer-events-none">
                <button
                    v-for="bar in week.bars"
                    :key="`${bar.event.id}-${bar.lane}`"
                    type="button"
                    class="absolute pointer-events-auto text-left text-2xs truncate px-1.5 py-px transition-opacity hover:opacity-80"
                    :class="[
                        bar.continuesBefore ? '' : 'rounded-l',
                        bar.continuesAfter ? '' : 'rounded-r',
                    ]"
                    :style="{
                        left: `calc(${(bar.from / 7) * 100}% + 0.25rem)`,
                        width: `calc(${(bar.span / 7) * 100}% - 0.5rem)`,
                        top: `${bar.lane * 1.25}rem`,
                        backgroundColor: `color-mix(in srgb, var(--chart-cat-${bar.event.colourSlot}) 18%, transparent)`,
                        color: `var(--chart-cat-${bar.event.colourSlot})`,
                        borderLeft: bar.continuesBefore ? 'none' : `2px solid var(--chart-cat-${bar.event.colourSlot})`,
                    }"
                    v-on:click="$emit('open-event', bar.event)"
                >
                    {{ bar.event.title }}
                </button>
            </div>
        </div>
    </div>
</template>
