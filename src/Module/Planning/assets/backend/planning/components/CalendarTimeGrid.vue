<script setup>
/**
 * The day and week views: hours down the side, days across the top.
 *
 * One component for both, because they differ only in how many columns they
 * draw. Two components would each carry the same hour gutter, the same all-day
 * band and the same now line, and the day view would be where a fix was
 * forgotten.
 *
 * Draws what `timeGrid.js` works out and decides nothing about dates itself. The
 * positions arrive as fractions of the day, so the row height lives here and
 * only here - the arithmetic never had to be told what a pixel is.
 */
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Check } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import {
    HOURS,
    allDayBand,
    daysFromPixels,
    draftAt,
    layOutDay,
    minutesFromPixels,
    nowOffset,
    resizedSpan,
    shiftedSpan,
    timeAt,
    visibleDays,
} from "../composables/timeGrid.js";
import { dayKey, sameDay } from "../composables/monthGrid.js";

const props = defineProps({
    /** Any date inside the range being shown. */
    anchor: { type: Date, required: true },
    /** `day` or `week`. */
    view: { type: String, required: true },
    /** Serialised events overlapping the window. */
    events: { type: Array, required: true },
    /** Serialised reminders falling inside it. */
    reminders: { type: Array, default: () => [] },
});

const emit = defineEmits(["open-event", "open-reminder", "toggle-reminder", "add-on", "move-event"]);

const { d, t } = useI18n();

/** One hour of height. The whole grid is 24 of these. */
const HOUR_REM = 3;

const days = computed(() => visibleDays(props.anchor, props.view));

const band = computed(() => allDayBand(days.value, props.events));

const columns = computed(() =>
    days.value.map((day) => ({
        day,
        key: dayKey(day),
        blocks: layOutDay(day, props.events),
    })),
);

/**
 * Re-read every minute, so the line moves without a reload.
 *
 * A ref rather than a computed over `new Date()`: a computed has no reason to
 * re-evaluate as time passes, so the line would freeze wherever the page
 * happened to load.
 */
const now = ref(new Date());
let ticker = null;

/** The scrolling box, so the day can open somewhere worth looking at. */
const hours = ref(null);

/** The columns' own box, for turning pointer pixels into minutes and days. */
const columnsBox = ref(null);

/**
 * The drag in progress, or null.
 *
 * One object rather than four refs, so a half-finished drag cannot exist: either
 * every field is there or the gesture is not happening.
 */
const drag = ref(null);

/**
 * Whether a drag has moved far enough to be a drag.
 *
 * Without a threshold every click on an event is a zero-pixel move, and the grid
 * would post an update on each one - and the click that opens the event would
 * stop working, because the pointer handler would have claimed it.
 */
const DRAG_THRESHOLD_PX = 4;

/**
 * Set when a drag ends, so the click that follows it does nothing.
 *
 * A browser fires `click` after `pointerup` on the same element, so without this
 * every drag also opened the event's modal. Cleared on the next `pointerdown`
 * rather than on a timer: if no click follows - the pointer left the block, or
 * the gesture was cancelled - the flag must not swallow the next real click.
 */
let dragEndedWithMovement = false;

function onBlockPointerDown(block, mode, pointerEvent) {
    // Left button only. A right-click is a context menu and a middle-click is a
    // scroll; neither is a request to move a meeting.
    if (0 !== pointerEvent.button || block.event.readOnly) {
        return;
    }

    pointerEvent.stopPropagation();
    dragEndedWithMovement = false;

    drag.value = {
        id: block.event.id,
        // Carried so the app can tell a series from a single event without looking
        // it up. Without it a drag on one occurrence moved the whole series, and
        // silently: nothing asked which it meant.
        event: block.event,
        mode,
        startAt: block.event.startAt,
        endAt: block.event.endAt,
        originX: pointerEvent.clientX,
        originY: pointerEvent.clientY,
        minutes: 0,
        days: 0,
        moved: false,
    };

    window.addEventListener("pointermove", onPointerMove);
    window.addEventListener("pointerup", onPointerUp);
}

function onPointerMove(pointerEvent) {
    const current = drag.value;
    if (null === current || null === columnsBox.value) {
        return;
    }

    const box = columnsBox.value.getBoundingClientRect();
    const deltaX = pointerEvent.clientX - current.originX;
    const deltaY = pointerEvent.clientY - current.originY;

    if (Math.abs(deltaX) > DRAG_THRESHOLD_PX || Math.abs(deltaY) > DRAG_THRESHOLD_PX) {
        current.moved = true;
    }

    current.minutes = minutesFromPixels(deltaY, box.height);
    // Sideways only when moving. Resizing an event into another day is not a
    // gesture anybody makes on purpose, and a wobbling hand would do it.
    current.days = "move" === current.mode
        ? daysFromPixels(deltaX, box.width / days.value.length)
        : 0;
}

function onPointerUp() {
    const current = drag.value;
    drag.value = null;
    window.removeEventListener("pointermove", onPointerMove);
    window.removeEventListener("pointerup", onPointerUp);

    if (null === current || !current.moved) {
        return;
    }

    // Dragged, so whatever the browser sends next is not a click on the event.
    dragEndedWithMovement = true;

    if (0 === current.minutes && 0 === current.days) {
        // Dragged and put back. Nothing to save, and posting an unchanged span
        // would write an audit line saying somebody moved it.
        return;
    }

    const span = "move" === current.mode
        ? shiftedSpan(current.startAt, current.endAt, current.minutes, current.days)
        : resizedSpan(current.startAt, current.endAt, current.minutes);

    emit("move-event", { id: current.id, event: current.event, ...span });
}

onBeforeUnmount(() => {
    window.removeEventListener("pointermove", onPointerMove);
    window.removeEventListener("pointerup", onPointerUp);
});

/**
 * How far to draw a block from where its data says it is.
 *
 * The grid shows the drag before the server has agreed to it, because a block
 * that only moves after a round trip feels like it did not take.
 */
/**
 * Opening an event, unless the pointer was dragging it.
 *
 * Guarded rather than prevented on the element, because `preventDefault` on
 * `pointerdown` would also stop the browser giving the block focus - and then the
 * keyboard could not reach it at all.
 */
function onBlockClick(event) {
    if (dragEndedWithMovement) {
        dragEndedWithMovement = false;

        return;
    }

    emit("open-event", event);
}

function dragOffset(block) {
    const current = drag.value;
    if (null === current || current.id !== block.event.id) {
        return { top: 0, height: 0, days: 0 };
    }

    const perMinute = 1 / 1440;

    return "move" === current.mode
        ? { top: current.minutes * perMinute, height: 0, days: current.days }
        : { top: 0, height: current.minutes * perMinute, days: 0 };
}

/**
 * The hour the grid opens on.
 *
 * An hour before now when today is on screen, so there is context above the line
 * rather than it sitting against the top edge; 08:00 otherwise, because a range
 * that is not today has no "now" and a working day is the useful guess.
 *
 * Without this the grid opened at 00:00 and the reader arrived in the middle of
 * the night, which is a scroll every single time.
 */
function openingHour() {
    const today = days.value.some((day) => sameDay(day, new Date()));

    return today ? Math.max(0, new Date().getHours() - 1) : 8;
}

onMounted(() => {
    ticker = window.setInterval(() => {
        now.value = new Date();
    }, 60000);

    if (null !== hours.value) {
        // Read off the element rather than converting rem to pixels here: the box
        // knows its own scroll height, and a hard-coded 16 would be wrong the day
        // somebody changes the root font size.
        hours.value.scrollTop = (openingHour() / HOURS.length) * hours.value.scrollHeight;
    }
});

onBeforeUnmount(() => {
    if (null !== ticker) {
        window.clearInterval(ticker);
    }
});

const today = computed(() => new Date());

function isToday(day) {
    return sameDay(day, today.value);
}

function nowLine(day) {
    return nowOffset(day, now.value);
}

function dayLabel(day) {
    return d(day, { weekday: "short" });
}

function hourLabel(hour) {
    // Formatted through the locale so a 12-hour language says "1 PM" rather than
    // a "13:00" written out here.
    return d(new Date(2026, 0, 1, hour), { hour: "2-digit", minute: "2-digit" });
}

function remindersOn(day) {
    return props.reminders
        .filter((reminder) => sameDay(new Date(reminder.dueAt), day))
        .sort((a, b) => new Date(a.dueAt) - new Date(b.dueAt));
}

function timeOf(event) {
    return d(new Date(event.startAt), { hour: "2-digit", minute: "2-digit" });
}

/**
 * Clicking an empty part of a column starts an event at that time.
 *
 * The time comes from where the pointer was, measured against the column's own
 * box rather than from `offsetY`: the hour lines and the now marker are children
 * of the column, and `offsetY` against one of those is a few pixels from the top
 * of an hour rather than from the top of the day.
 */
function addAt(day, mouseEvent) {
    const box = mouseEvent.currentTarget.getBoundingClientRect();

    emit("add-on", draftAt(timeAt(day, (mouseEvent.clientY - box.top) / box.height)));
}

/** Clicking the band starts something that owns the whole day. */
function addAllDayOn(day) {
    const start = new Date(day);
    start.setHours(0, 0, 0, 0);

    emit("add-on", draftAt(start, true));
}
</script>

<template>
    <div class="bg-surface border border-line rounded-xl overflow-hidden">
        <!-- Header: the hour gutter's width is repeated in three places below,
             so the columns line up with the labels. -->
        <div class="flex border-b border-line">
            <span class="w-11 shrink-0 border-r border-line sm:w-14" />
            <div class="grid flex-1" :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }">
                <div
                    v-for="day in days"
                    :key="`h-${day.toISOString()}`"
                    class="border-r border-line last:border-r-0 px-1.5 py-1.5 flex flex-col items-center gap-0.5 sm:flex-row sm:items-baseline sm:gap-1.5 sm:px-2"
                >
                    <span class="text-2xs font-semibold uppercase tracking-wider text-muted">
                        {{ dayLabel(day) }}
                    </span>
                    <span
                        class="text-sm tabular-nums"
                        :class="isToday(day) ? 'rounded-full bg-accent-600 px-1.5 font-semibold text-white' : 'text-secondary'"
                    >{{ day.getDate() }}</span>
                </div>
            </div>
        </div>

        <!-- Reminders, in their own strip rather than placed at their hour.
             A reminder is owed by a time, not happening at one, so putting it in
             the column would say it occupies fifteen minutes - and a ticked one
             would still be sitting in the middle of the afternoon. -->
        <div v-if="reminders.length" class="flex border-b border-line">
            <span
                class="w-11 shrink-0 border-r border-line px-1.5 py-1 text-2xs uppercase tracking-wider text-muted sm:w-14 sm:px-2"
            >{{ t("backend.plannings.reminders.title") }}</span>
            <div class="grid flex-1" :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }">
                <div
                    v-for="day in days"
                    :key="`rem-${day.toISOString()}`"
                    class="border-r border-line last:border-r-0 px-1.5 py-1 flex flex-col gap-1 min-w-0"
                >
                    <div
                        v-for="reminder in remindersOn(day)"
                        :key="reminder.id"
                        class="flex items-center gap-1.5 text-2xs min-w-0"
                    >
                        <button
                            type="button"
                            class="shrink-0 w-3 h-3 rounded-sm border cursor-pointer transition-colors flex items-center justify-center"
                            :class="reminder.completed ? 'border-transparent' : 'border-secondary hover:border-accent'"
                            :style="reminder.completed
                                ? { backgroundColor: `var(--chart-cat-${reminder.colourSlot})` }
                                : {}"
                            :aria-pressed="reminder.completed"
                            :title="t('backend.plannings.reminders.completed')"
                            v-on:click="emit('toggle-reminder', reminder)"
                        >
                            <Check v-if="reminder.completed" class="w-2.5 h-2.5 text-white" :stroke-width="3" />
                        </button>
                        <button
                            type="button"
                            class="min-w-0 truncate text-left cursor-pointer"
                            :class="reminder.completed
                                ? 'line-through text-muted'
                                : (reminder.overdue ? 'text-red-500' : 'text-secondary')"
                            v-on:click="emit('open-reminder', reminder)"
                        >
                            {{ reminder.title }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- The all-day band. Present only when it has something in it: an empty
             strip above every week would cost a row of height to say nothing. -->
        <div v-if="band.length" class="flex border-b border-line">
            <span
                class="w-11 shrink-0 border-r border-line px-1.5 py-1 text-2xs uppercase tracking-wider text-muted sm:w-14 sm:px-2"
            >{{ t("backend.plannings.events.all_day_short") }}</span>
            <div class="relative flex-1 py-1" :style="{ minHeight: `${band.length * 1.25 + 0.5}rem` }">
                <!-- One transparent target per day under the bars, so clicking an
                     empty part of the strip knows which day it landed on. -->
                <div
                    class="absolute inset-0 grid"
                    :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }"
                >
                    <button
                        v-for="day in days"
                        :key="`band-${day.toISOString()}`"
                        type="button"
                        class="cursor-pointer"
                        :aria-label="t('backend.plannings.events.new')"
                        v-on:click="addAllDayOn(day)"
                    />
                </div>
                <button
                    v-for="(bar, index) in band"
                    :key="`${bar.event.id}-${index}`"
                    type="button"
                    class="absolute text-left text-2xs truncate px-1.5 py-px transition-opacity hover:opacity-80 cursor-pointer"
                    :class="[bar.continuesBefore ? '' : 'rounded-l', bar.continuesAfter ? '' : 'rounded-r']"
                    :style="{
                        left: `calc(${(bar.from / days.length) * 100}% + 0.25rem)`,
                        width: `calc(${(bar.span / days.length) * 100}% - 0.5rem)`,
                        top: `${index * 1.25 + 0.25}rem`,
                        backgroundColor: `color-mix(in srgb, var(--chart-cat-${bar.event.colourSlot}) 18%, transparent)`,
                        color: `var(--chart-cat-${bar.event.colourSlot})`,
                        borderLeft: bar.continuesBefore ? 'none' : `2px solid var(--chart-cat-${bar.event.colourSlot})`,
                    }"
                    v-on:click.stop="emit('open-event', bar.event)"
                >
                    {{ bar.event.title }}
                </button>
            </div>
        </div>

        <!-- The hours. Scrolls on its own so the header and the band stay put:
             24 rows is taller than the screen, and a reader looking at 15:00
             still needs to know which day they are in. -->
        <!-- Taller than the viewport by design, so it scrolls inside itself and
             the header stays put. Capped shorter on a phone, where a 36rem box
             pushes everything else off the screen. -->
        <div ref="hours" class="flex max-h-[24rem] overflow-y-auto sm:max-h-[36rem]">
            <div class="w-11 shrink-0 border-r border-line sm:w-14">
                <!-- No bottom border here. The rule belongs to the columns, and
                     a gutter drawing its own turned each hour into a tick mark
                     stopping at the labels - which is where the grid stopped
                     looking like a grid. Google starts the rule after the gutter
                     too. -->
                <div
                    v-for="hour in HOURS"
                    :key="`g-${hour}`"
                    class="relative"
                    :style="{ height: `${HOUR_REM}rem` }"
                >
                    <!-- Pulled up half a line and hidden on the first row: an
                         hour label names the line it sits on, and the 00:00 line
                         is the top edge, where a label would be clipped. -->
                    <span
                        v-if="hour > 0"
                        class="absolute -top-2 right-1.5 text-2xs tabular-nums text-muted"
                    >{{ hourLabel(hour) }}</span>
                </div>
            </div>

            <div
                ref="columnsBox"
                class="grid flex-1"
                :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }"
            >
                <div
                    v-for="column in columns"
                    :key="column.key"
                    class="relative border-r border-line last:border-r-0 cursor-pointer"
                    :style="{ height: `${HOURS.length * HOUR_REM}rem` }"
                    v-on:click="addAt(column.day, $event)"
                >
                    <!-- Hour lines, drawn per column rather than once behind the
                         grid, so they stop at the column's own border instead of
                         crossing it.

                         At the same strength as the vertical separators. At 60%
                         they were almost invisible in the dark theme, where
                         `--color-line` is already a dark slate - so the grid read
                         as seven empty columns with tick marks beside them. -->
                    <div
                        v-for="hour in HOURS"
                        :key="`l-${column.key}-${hour}`"
                        class="absolute inset-x-0 border-b border-line pointer-events-none"
                        :style="{ top: `${hour * HOUR_REM}rem`, height: `${HOUR_REM}rem` }"
                    />

                    <!-- Now. Only on today, and above the events, because it is
                         the one thing the reader looks for without scanning. -->
                    <div
                        v-if="null !== nowLine(column.day)"
                        class="absolute inset-x-0 z-20 border-t-2 border-red-500 pointer-events-none"
                        :style="{ top: `${nowLine(column.day) * HOURS.length * HOUR_REM}rem` }"
                    >
                        <span class="absolute -left-1 -top-[5px] w-2 h-2 rounded-full bg-red-500" />
                    </div>

                    <div
                        v-for="block in column.blocks"
                        :key="block.event.id"
                        class="absolute z-10 overflow-hidden rounded text-2xs leading-tight transition-opacity hover:opacity-80"
                        :class="[
                            block.event.readOnly ? 'cursor-pointer' : 'cursor-grab',
                            drag?.id === block.event.id ? 'z-20 opacity-80 shadow-lg' : '',
                        ]"
                        :style="{
                            top: `${(block.top + dragOffset(block).top) * HOURS.length * HOUR_REM}rem`,
                            height: `${(block.height + dragOffset(block).height) * HOURS.length * HOUR_REM}rem`,
                            left: `calc(${((block.column / block.columns) + dragOffset(block).days) * 100}% + 1px)`,
                            width: `calc(${(1 / block.columns) * 100}% - 3px)`,
                            backgroundColor: `color-mix(in srgb, var(--chart-cat-${block.event.colourSlot}) 18%, transparent)`,
                            color: `var(--chart-cat-${block.event.colourSlot})`,
                            borderLeft: `2px solid var(--chart-cat-${block.event.colourSlot})`,
                        }"
                        v-on:pointerdown="onBlockPointerDown(block, 'move', $event)"
                        v-on:click.stop="onBlockClick(block.event)"
                    >
                        <div class="px-1.5 py-px">
                            <span class="block truncate font-medium">{{ block.event.title }}</span>
                            <!-- The time only when the block is tall enough to
                                 hold a second line. Below that it would push the
                                 title out of view, and the title is what
                                 identifies the event. -->
                            <span v-if="block.height > 45 / 1440" class="block truncate tabular-nums opacity-80">
                                {{ timeOf(block.event) }}
                            </span>
                        </div>

                        <!-- The resize grip. Four pixels of it, at the bottom
                             edge, because that is where the end of an event is -
                             and a grip anywhere else would be a second way to
                             move it. -->
                        <span
                            v-if="!block.event.readOnly"
                            class="absolute inset-x-0 bottom-0 h-1 cursor-ns-resize"
                            v-on:pointerdown.stop="onBlockPointerDown(block, 'resize', $event)"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
