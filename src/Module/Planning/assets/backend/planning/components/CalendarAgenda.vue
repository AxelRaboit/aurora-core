<script setup>
/**
 * Everything in the range, as a sequence.
 *
 * Google calls this Schedule and it earns its place next to the grids rather than
 * replacing one: a grid shows the shape of a month, gaps included, and this shows
 * the order of what is in it. Days with nothing are left out, because a line
 * saying "nothing on the 12th" would be a grid drawn in one column.
 *
 * The same range as the month view, so paging means the same thing in both.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Check } from "lucide-vue-next";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { agendaDays, sameDay } from "../composables/monthGrid.js";

const props = defineProps({
    /** The range's cells, from `monthGrid`. */
    cells: { type: Array, required: true },
    events: { type: Array, required: true },
    reminders: { type: Array, default: () => [] },
});

const emit = defineEmits(["open-event", "open-reminder", "toggle-reminder"]);

const { t, d } = useI18n();

const today = new Date();

const days = computed(() => agendaDays(props.cells, props.events, props.reminders));

function isToday(date) {
    return sameDay(date, today);
}
</script>

<template>
    <div class="bg-surface border border-line rounded-xl overflow-hidden">
        <AppNoData v-if="!days.length" class="py-10" :message="t('backend.plannings.nothing_in_range')" />

        <div
            v-for="day in days"
            :key="day.key"
            class="flex gap-3 border-b border-line last:border-b-0 px-3 py-2.5 sm:px-4"
        >
            <!-- The date in its own column, so the titles line up down the page
                 and the eye can run past the dates it does not want. -->
            <div class="w-16 shrink-0 sm:w-24">
                <p
                    class="text-sm tabular-nums leading-tight"
                    :class="isToday(day.date) ? 'font-semibold text-accent-600' : 'text-primary'"
                >
                    {{ d(day.date, { day: "numeric", month: "short" }) }}
                </p>
                <p class="text-2xs uppercase tracking-wider text-muted">
                    {{ d(day.date, { weekday: "short" }) }}
                </p>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                <div
                    v-for="entry in day.items"
                    :key="`${entry.kind}-${entry.item.id}`"
                    class="flex items-start gap-2 min-w-0"
                >
                    <!-- A reminder gets a checkbox, an event a colour bar: one is
                         finished by you, the other by time passing. -->
                    <button
                        v-if="'reminder' === entry.kind"
                        type="button"
                        class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border transition-colors cursor-pointer"
                        :class="entry.item.completed ? 'border-transparent' : 'border-secondary hover:border-accent'"
                        :style="entry.item.completed
                            ? { backgroundColor: `var(--chart-cat-${entry.item.colourSlot})` }
                            : {}"
                        :aria-pressed="entry.item.completed"
                        :title="t('backend.plannings.reminders.completed')"
                        v-on:click="emit('toggle-reminder', entry.item)"
                    >
                        <Check v-if="entry.item.completed" class="h-3 w-3 text-white" :stroke-width="3" />
                    </button>
                    <span
                        v-else
                        class="mt-1 h-3.5 w-[3px] shrink-0 rounded-sm"
                        :style="{ backgroundColor: `var(--chart-cat-${entry.item.colourSlot})` }"
                    />

                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-baseline gap-2 text-left cursor-pointer"
                        v-on:click="emit(
                            'reminder' === entry.kind ? 'open-reminder' : 'open-event',
                            entry.item,
                        )"
                    >
                        <span
                            class="min-w-0 flex-1 truncate text-sm"
                            :class="'reminder' === entry.kind && entry.item.completed
                                ? 'line-through text-muted'
                                : 'text-primary'"
                        >{{ entry.item.title }}</span>
                        <span
                            class="shrink-0 text-2xs tabular-nums"
                            :class="'reminder' === entry.kind && entry.item.overdue && !entry.item.completed
                                ? 'text-red-500'
                                : 'text-muted'"
                        >
                            {{ entry.whole
                                ? t("backend.plannings.events.all_day")
                                : d(entry.at, { hour: "2-digit", minute: "2-digit" }) }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
