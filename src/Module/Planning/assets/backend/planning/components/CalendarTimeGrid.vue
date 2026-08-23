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
import { useI18n } from "vue-i18n";
import { HOURS, allDayBand, layOutDay, nowOffset, visibleDays } from "../composables/timeGrid.js";
import { sameDay } from "../composables/monthGrid.js";

const props = defineProps({
    /** Any date inside the range being shown. */
    anchor: { type: Date, required: true },
    /** `day` or `week`. */
    view: { type: String, required: true },
    /** Serialised events overlapping the window. */
    events: { type: Array, required: true },
});

const emit = defineEmits(["open-event"]);

const { d, t } = useI18n();

/** One hour of height. The whole grid is 24 of these. */
const HOUR_REM = 3;

const days = computed(() => visibleDays(props.anchor, props.view));

const band = computed(() => allDayBand(days.value, props.events));

const columns = computed(() =>
    days.value.map((day) => ({
        day,
        key: day.toISOString().slice(0, 10),
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

onMounted(() => {
    ticker = window.setInterval(() => {
        now.value = new Date();
    }, 60000);
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

function timeOf(event) {
    return d(new Date(event.startAt), { hour: "2-digit", minute: "2-digit" });
}
</script>

<template>
    <div class="bg-surface border border-line rounded-xl overflow-hidden">
        <!-- Header: the hour gutter's width is repeated in three places below,
             so the columns line up with the labels. -->
        <div class="flex border-b border-line">
            <span class="w-14 shrink-0 border-r border-line" />
            <div class="grid flex-1" :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }">
                <div
                    v-for="day in days"
                    :key="`h-${day.toISOString()}`"
                    class="border-r border-line last:border-r-0 px-2 py-1.5 flex items-baseline gap-1.5"
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

        <!-- The all-day band. Present only when it has something in it: an empty
             strip above every week would cost a row of height to say nothing. -->
        <div v-if="band.length" class="flex border-b border-line">
            <span
                class="w-14 shrink-0 border-r border-line px-2 py-1 text-2xs uppercase tracking-wider text-muted"
            >{{ t("backend.plannings.events.all_day_short") }}</span>
            <div class="relative flex-1 py-1" :style="{ minHeight: `${band.length * 1.25 + 0.5}rem` }">
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
                    v-on:click="emit('open-event', bar.event)"
                >
                    {{ bar.event.title }}
                </button>
            </div>
        </div>

        <!-- The hours. Scrolls on its own so the header and the band stay put:
             24 rows is taller than the screen, and a reader looking at 15:00
             still needs to know which day they are in. -->
        <div class="flex max-h-[36rem] overflow-y-auto">
            <div class="w-14 shrink-0 border-r border-line">
                <div
                    v-for="hour in HOURS"
                    :key="`g-${hour}`"
                    class="relative border-b border-line last:border-b-0"
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
                class="grid flex-1"
                :style="{ gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))` }"
            >
                <div
                    v-for="column in columns"
                    :key="column.key"
                    class="relative border-r border-line last:border-r-0"
                    :style="{ height: `${HOURS.length * HOUR_REM}rem` }"
                >
                    <!-- Hour lines, drawn per column rather than once behind the
                         grid, so they stop at the column's own border instead of
                         crossing it. -->
                    <div
                        v-for="hour in HOURS"
                        :key="`l-${column.key}-${hour}`"
                        class="absolute inset-x-0 border-b border-line/60"
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

                    <button
                        v-for="block in column.blocks"
                        :key="block.event.id"
                        type="button"
                        class="absolute z-10 overflow-hidden rounded px-1.5 py-px text-left text-2xs leading-tight transition-opacity hover:opacity-80 cursor-pointer"
                        :style="{
                            top: `${block.top * HOURS.length * HOUR_REM}rem`,
                            height: `${block.height * HOURS.length * HOUR_REM}rem`,
                            left: `calc(${(block.column / block.columns) * 100}% + 1px)`,
                            width: `calc(${(1 / block.columns) * 100}% - 3px)`,
                            backgroundColor: `color-mix(in srgb, var(--chart-cat-${block.event.colourSlot}) 18%, transparent)`,
                            color: `var(--chart-cat-${block.event.colourSlot})`,
                            borderLeft: `2px solid var(--chart-cat-${block.event.colourSlot})`,
                        }"
                        v-on:click="emit('open-event', block.event)"
                    >
                        <span class="block truncate font-medium">{{ block.event.title }}</span>
                        <!-- The time only when the block is tall enough to hold a
                             second line. Below that it would push the title out
                             of view, and the title is what identifies the event. -->
                        <span v-if="block.height > 45 / 1440" class="block truncate tabular-nums opacity-80">
                            {{ timeOf(block.event) }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
