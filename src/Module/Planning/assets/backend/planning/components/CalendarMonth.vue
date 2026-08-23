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
import { computed, onBeforeUnmount, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Check } from "lucide-vue-next";
import { countsPerDay, layOutWeek, sameDay, timedEventsOn } from "../composables/monthGrid.js";
import { defaultTimeOn, draftAt, shiftedSpan } from "../composables/timeGrid.js";

const props = defineProps({
    /** The 42 cells from `monthGrid`. */
    cells: { type: Array, required: true },
    /** Serialised events overlapping the grid's window. */
    events: { type: Array, required: true },
    /** Serialised reminders falling inside it. */
    reminders: { type: Array, default: () => [] },
    /**
     * Dots instead of titles, and a tap selects rather than creates.
     *
     * A month cell on a phone is about fifty pixels wide: enough for a day number
     * and a few dots, nothing like enough for a title. Both Google and Apple show
     * the grid as an index and put the contents in a list underneath.
     */
    compact: { type: Boolean, default: false },
    /** The day the list below is showing, so the grid can mark it. */
    selected: { type: Date, default: null },
});

const emit = defineEmits(["open-event", "open-reminder", "toggle-reminder", "add-on", "select-day", "move-event"]);

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
        const { bars, hiddenPerDay, lanesPerDay } = layOutWeek(cells[0].date, props.events);

        return {
            row,
            cells,
            bars,
            hiddenPerDay,
            lanesPerDay,
            // Only computed for the compact grid, where it is what a cell draws.
            counts: props.compact ? countsPerDay(cells, props.events, props.reminders) : [],
        };
    }),
);

/** Up to three dots, then a count - past three they stop being countable. */
const MAX_DOTS = 3;

function isSelected(date) {
    return null !== props.selected && sameDay(date, props.selected);
}

/**
 * A tap means two different things on the two grids.
 *
 * Wide: start something here, because the cell already shows what is on it.
 * Narrow: show me this day, because the cell cannot. Creating there has its own
 * control in the list's header - one gesture cannot honestly do both.
 */
function onCellClick(date) {
    if (props.compact) {
        emit("select-day", date);

        return;
    }

    addOn(date);
}

function isToday(date) {
    return sameDay(date, today);
}

function timedOn(date) {
    return timedEventsOn(date, props.events);
}

/**
 * The reminders due on one day, in order.
 *
 * Drawn under the timed events rather than mixed in by time: a reminder is not
 * something happening at an hour, it is something owed by one - so grouping them
 * reads as a small list of things to do on that day, which is what they are.
 */
function remindersOn(date) {
    return props.reminders
        .filter((reminder) => sameDay(new Date(reminder.dueAt), date))
        .sort((a, b) => new Date(a.dueAt) - new Date(b.dueAt));
}

function timeOf(event) {
    return d(new Date(event.startAt), { hour: "2-digit", minute: "2-digit" });
}

/**
 * Clicking an empty part of a cell starts an event on that day.
 *
 * A month cell carries a day and no time, so the hour is a constant rather than
 * the current clock - which would put a meeting at 23:45 for somebody working
 * late. The reader sees both ends in the form and can change either.
 */
/**
 * The drag in progress, or null.
 *
 * The month only moves things by whole days: an event dragged from Tuesday to
 * Friday keeps its time, because the cell it lands in says nothing about hours.
 */
const drag = ref(null);

const DRAG_THRESHOLD_PX = 4;

/** Set when a drag ends, so the click the browser fires next does nothing. */
let dragEndedWithMovement = false;

/**
 * The day a cell stands for, read back off the DOM.
 *
 * Computed from the pointer rather than from arithmetic on a row height, because
 * the rows here are not all the same height - a week carrying two bars reserves
 * two lanes and the one below it none.
 */
function dayUnder(x, y) {
    const cell = document.elementFromPoint(x, y)?.closest("[data-day]");

    return cell?.dataset.day ?? null;
}

/**
 * Where the pointer grabbed, as a date.
 *
 * A chip lives inside its cell, so it can be asked. A bar is positioned over the
 * whole week and has no cell to climb to, so its column comes from the pointer's
 * position across a row that is - horizontally - seven equal parts.
 */
function grabbedDay(pointerEvent, week) {
    const fromChip = pointerEvent.target.closest?.("[data-day]")?.dataset.day;
    if (fromChip) {
        return fromChip;
    }

    const row = pointerEvent.currentTarget.closest("[data-week]");
    if (!row) {
        return null;
    }

    const box = row.getBoundingClientRect();
    const column = Math.min(6, Math.max(0, Math.floor((pointerEvent.clientX - box.left) / (box.width / 7))));

    return week.cells[column]?.key ?? null;
}

function onEventPointerDown(event, week, pointerEvent) {
    if (0 !== pointerEvent.button || event.readOnly) {
        return;
    }

    const from = grabbedDay(pointerEvent, week);
    if (null === from) {
        return;
    }

    dragEndedWithMovement = false;
    drag.value = {
        event,
        from,
        originX: pointerEvent.clientX,
        originY: pointerEvent.clientY,
        moved: false,
    };

    window.addEventListener("pointermove", onPointerMove);
    window.addEventListener("pointerup", onPointerUp);
}

function onPointerMove(pointerEvent) {
    const current = drag.value;
    if (null === current) {
        return;
    }

    if (
        Math.abs(pointerEvent.clientX - current.originX) > DRAG_THRESHOLD_PX
        || Math.abs(pointerEvent.clientY - current.originY) > DRAG_THRESHOLD_PX
    ) {
        current.moved = true;
    }
}

function onPointerUp(pointerEvent) {
    const current = drag.value;
    drag.value = null;
    window.removeEventListener("pointermove", onPointerMove);
    window.removeEventListener("pointerup", onPointerUp);

    if (null === current || !current.moved) {
        return;
    }

    dragEndedWithMovement = true;

    const to = dayUnder(pointerEvent.clientX, pointerEvent.clientY);
    if (null === to || to === current.from) {
        // Dropped outside the grid, or back where it started. Nothing to save,
        // and posting an unchanged span would log that somebody moved it.
        return;
    }

    // Whole days, computed from the two dates rather than from pixels: the cells
    // already told us which days these are.
    const days = Math.round((new Date(to) - new Date(current.from)) / 86400000);

    emit("move-event", {
        id: current.event.id,
        // Carried for the same reason the hourly grid carries it: the app has to
        // know whether this is one occurrence of a series before it writes.
        event: current.event,
        ...shiftedSpan(current.event.startAt, current.event.endAt, 0, days),
    });
}

onBeforeUnmount(() => {
    window.removeEventListener("pointermove", onPointerMove);
    window.removeEventListener("pointerup", onPointerUp);
});

/** Opening an event, unless the pointer was dragging it. */
function onEventClick(event) {
    if (dragEndedWithMovement) {
        dragEndedWithMovement = false;

        return;
    }

    emit("open-event", event);
}

function addOn(date) {
    emit("add-on", draftAt(defaultTimeOn(date)));
}
</script>

<template>
    <div class="bg-surface border border-line rounded-xl overflow-hidden">
        <div class="grid grid-cols-7 border-b border-line">
            <span
                v-for="day in weekdays"
                :key="day"
                class="py-1.5 text-2xs font-semibold uppercase tracking-wider text-muted"
                :class="compact ? 'text-center' : 'px-2'"
            >
                {{ day }}
            </span>
        </div>

        <div
            v-for="week in weeks"
            :key="week.row"
            data-week
            class="relative border-b border-line last:border-b-0"
        >
            <!-- The day cells. Their padding-top leaves room for the bars, which
                 are positioned over the row rather than inside a cell: a bar
                 belongs to the week, and nesting it in the first day's cell would
                 clip it at that cell's edge. -->
            <div class="grid grid-cols-7">
                <div
                    v-for="cell in week.cells"
                    :key="cell.key"
                    :data-day="cell.key"
                    class="border-r border-line last:border-r-0 flex flex-col cursor-pointer"
                    :class="[
                        compact
                            ? 'min-h-[3.25rem] items-center gap-1 px-0.5 pb-1 pt-1'
                            : 'min-h-[6.5rem] gap-1 px-1.5 pb-1.5',
                        cell.inMonth ? '' : 'bg-surface-2/40',
                        compact && isSelected(cell.date) ? 'bg-accent/10' : '',
                    ]"
                    :style="compact
                        ? {}
                        : { paddingTop: `${1.75 + week.lanesPerDay[week.cells.indexOf(cell)] * 1.25}rem` }"
                    v-on:click="onCellClick(cell.date)"
                >
                    <!-- Compact: the day number in place, then dots. The number
                         moves inside the cell here rather than living in the
                         overlay the wide grid uses, because there are no bars to
                         sit under and centring reads better at this size. -->
                    <template v-if="compact">
                        <span
                            class="text-xs tabular-nums leading-none"
                            :class="[
                                cell.inMonth ? 'text-secondary' : 'text-muted',
                                isSelected(cell.date) ? 'font-semibold text-primary' : '',
                            ]"
                        >
                            <span
                                v-if="isToday(cell.date)"
                                class="inline-block rounded-full bg-accent-600 px-1.5 font-semibold text-white"
                            >{{ cell.dayOfMonth }}</span>
                            <span v-else>{{ cell.dayOfMonth }}</span>
                        </span>

                        <span class="flex items-center gap-0.5 leading-none">
                            <span
                                v-for="dot in Math.min(MAX_DOTS, week.counts[week.cells.indexOf(cell)])"
                                :key="dot"
                                class="h-1 w-1 rounded-full bg-secondary"
                            />
                            <span
                                v-if="week.counts[week.cells.indexOf(cell)] > MAX_DOTS"
                                class="text-3xs text-muted tabular-nums"
                            >{{ week.counts[week.cells.indexOf(cell)] }}</span>
                        </span>
                    </template>

                    <button
                        v-for="event in compact ? [] : timedOn(cell.date)"
                        :key="event.id"
                        type="button"
                        class="flex items-center gap-1.5 text-left text-2xs rounded px-0.5 py-px hover:bg-surface-2 transition-colors min-w-0"
                        :class="event.readOnly ? '' : 'cursor-grab'"
                        v-on:pointerdown="onEventPointerDown(event, week, $event)"
                        v-on:click.stop="onEventClick(event)"
                    >
                        <span
                            class="w-1.5 h-1.5 rounded-full shrink-0"
                            :style="{ backgroundColor: `var(--chart-cat-${event.colourSlot})` }"
                        />
                        <span class="text-muted tabular-nums shrink-0">{{ timeOf(event) }}</span>
                        <span class="text-secondary truncate">{{ event.title }}</span>
                    </button>

                    <!-- Reminders. A checkbox and not a dot, because the state
                         is the point: an event is over when its time passes and a
                         reminder is not, so it keeps showing until it is ticked. -->
                    <div
                        v-for="reminder in compact ? [] : remindersOn(cell.date)"
                        :key="`r-${reminder.id}`"
                        class="flex items-center gap-1.5 text-2xs min-w-0"
                    >
                        <button
                            type="button"
                            class="shrink-0 w-3 h-3 rounded-sm border cursor-pointer transition-colors flex items-center justify-center"
                            :class="reminder.completed
                                ? 'border-transparent'
                                : 'border-secondary hover:border-accent'"
                            :style="reminder.completed
                                ? { backgroundColor: `var(--chart-cat-${reminder.colourSlot})` }
                                : {}"
                            :aria-pressed="reminder.completed"
                            :title="t('backend.plannings.reminders.completed')"
                            v-on:click.stop="emit('toggle-reminder', reminder)"
                        >
                            <Check v-if="reminder.completed" class="w-2.5 h-2.5 text-white" :stroke-width="3" />
                        </button>
                        <button
                            type="button"
                            class="min-w-0 truncate text-left cursor-pointer"
                            :class="reminder.completed
                                ? 'line-through text-muted'
                                : (reminder.overdue ? 'text-red-500' : 'text-secondary')"
                            v-on:click.stop="emit('open-reminder', reminder)"
                        >
                            {{ reminder.title }}
                        </button>
                    </div>

                    <span
                        v-if="!compact && week.hiddenPerDay[week.cells.indexOf(cell)] > 0"
                        class="text-2xs text-muted"
                    >
                        {{ t("backend.plannings.more", { count: week.hiddenPerDay[week.cells.indexOf(cell)] }) }}
                    </span>
                </div>
            </div>

            <!-- The day numbers, over the cells, so a bar can sit under them
                 without pushing them down. The compact grid draws its own inside
                 the cell instead - there is nothing to sit under there. -->
            <div v-if="!compact" class="absolute inset-x-0 top-0 grid grid-cols-7 pointer-events-none">
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
                 on into the next row. Absent when compact: a bar needs a title to
                 be worth its height, and there is no room for one. -->
            <div v-if="!compact" class="absolute inset-x-0 top-6 pointer-events-none">
                <button
                    v-for="bar in week.bars"
                    :key="`${bar.event.id}-${bar.lane}`"
                    type="button"
                    class="absolute pointer-events-auto text-left text-2xs truncate px-1.5 py-px transition-opacity hover:opacity-80"
                    :class="[
                        bar.continuesBefore ? '' : 'rounded-l',
                        bar.continuesAfter ? '' : 'rounded-r',
                        bar.event.readOnly ? '' : 'cursor-grab',
                    ]"
                    :style="{
                        left: `calc(${(bar.from / 7) * 100}% + 0.25rem)`,
                        width: `calc(${(bar.span / 7) * 100}% - 0.5rem)`,
                        top: `${bar.lane * 1.25}rem`,
                        backgroundColor: `color-mix(in srgb, var(--chart-cat-${bar.event.colourSlot}) 18%, transparent)`,
                        color: `var(--chart-cat-${bar.event.colourSlot})`,
                        borderLeft: bar.continuesBefore ? 'none' : `2px solid var(--chart-cat-${bar.event.colourSlot})`,
                    }"
                    v-on:pointerdown="onEventPointerDown(bar.event, week, $event)"
                    v-on:click.stop="onEventClick(bar.event)"
                >
                    {{ bar.event.title }}
                </button>
            </div>
        </div>
    </div>
</template>
