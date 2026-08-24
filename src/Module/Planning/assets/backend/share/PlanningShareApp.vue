<script setup>
/**
 * A calendar, for somebody who was sent a link.
 *
 * The backend's screen with everything removed that a guest cannot do: no sidebar,
 * no calendar list, no create buttons, no timezone picker, no modals. What is left
 * is what they were sent for - the grid, and the ability to move around it.
 *
 * It reuses `usePlanningCalendar` and both grids rather than reimplementing them.
 * A second month grid would be a second place for the same date arithmetic to be
 * wrong, and the guest's copy is exactly the one nobody would notice had drifted.
 *
 * The one thing it passes differently is the zone. A guest has no stored
 * preference and no control to change one, so the calendar's own zone is used and
 * named on screen: a reader in another country seeing 04:00 for a 10:00 shoot needs
 * to be told which clock that is.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarDays, ChevronLeft, ChevronRight } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import CalendarAgenda from "../planning/components/CalendarAgenda.vue";
import CalendarMonth from "../planning/components/CalendarMonth.vue";
import CalendarTimeGrid from "../planning/components/CalendarTimeGrid.vue";
import { usePlanningCalendar } from "../planning/composables/usePlanningCalendar.js";

const props = defineProps({
    calendars: { type: Array, default: () => [] },
    /** The calendar's own zone. Fixed: there is no control to change it. */
    zone: { type: String, required: true },
    eventsPath: { type: String, required: true },
});

const { t, d } = useI18n();

const {
    visibleEvents,
    visibleReminders,
    loading,
    year,
    month,
    view,
    effectiveView,
    usesMonthRange,
    narrow,
    anchor,
    cells,
    days,
    go,
    goToToday,
    setView,
    zone,
} = usePlanningCalendar(props, { fixedZone: props.zone });

/** The same three shapes the backend's toolbar uses, for the same reasons. */
const rangeLabel = computed(() => {
    if (usesMonthRange.value) {
        return d(new Date(year.value, month.value, 1), { month: "long", year: "numeric" });
    }

    if ("day" === view.value) {
        return d(anchor.value, { weekday: "long", day: "numeric", month: "long", year: "numeric" });
    }

    const first = days.value[0];
    const last = days.value[days.value.length - 1];

    if (!first || !last) {
        return "";
    }

    const sameMonth = first.getMonth() === last.getMonth();

    return `${d(first, sameMonth ? { day: "numeric" } : { day: "numeric", month: "short" })} - `
        + `${d(last, { day: "numeric", month: "long", year: "numeric" })}`;
});

const viewOptions = computed(() =>
    ["day", "week", "month", "agenda"].map((value) => ({
        value,
        label: t(`backend.plannings.views.${value}`),
    })),
);

/**
 * Which calendars this link covers, named.
 *
 * Read-only on purpose - a guest cannot fold one away. It is here so somebody
 * looking at a mixed grid knows what the colours mean, which is the only thing the
 * sidebar did that still applies.
 */
const legend = computed(() => props.calendars);
</script>

<template>
    <div class="relative space-y-3">
        <AppLoader :active="loading" />

        <div class="flex flex-wrap items-center gap-2">
            <AppIconButton :title="t('shared.common.previous')" v-on:click="go(-1)">
                <ChevronLeft class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton :title="t('shared.common.next')" v-on:click="go(1)">
                <ChevronRight class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
            <h2 class="min-w-0 truncate text-sm font-semibold text-primary first-letter:uppercase sm:text-base">
                {{ rangeLabel }}
            </h2>

            <div class="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                <div class="flex flex-1 overflow-hidden rounded-lg border border-line sm:flex-none">
                    <button
                        v-for="option in viewOptions"
                        :key="option.value"
                        type="button"
                        class="flex-1 cursor-pointer border-r border-line px-2.5 py-1 text-xs transition-colors last:border-r-0 sm:flex-none"
                        :class="view === option.value
                            ? 'bg-accent-600 text-white font-medium'
                            : 'text-secondary hover:bg-surface-2'"
                        :aria-pressed="view === option.value"
                        v-on:click="setView(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <AppButton variant="ghost" size="sm" v-on:click="goToToday">
                    {{ t("backend.plannings.today") }}
                </AppButton>
            </div>
        </div>

        <!-- What the colours mean, and the clock they are on. Both because this page
             has no sidebar to say either, and a guest cannot ask. -->
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-2xs text-muted">
            <span v-for="calendar in legend" :key="calendar.id" class="flex items-center gap-1.5">
                <span
                    class="h-2 w-2 rounded-full"
                    :style="{ backgroundColor: `var(--chart-cat-${calendar.colourSlot})` }"
                />
                {{ calendar.name }}
            </span>
            <span class="flex items-center gap-1.5">
                <CalendarDays class="h-3 w-3" :stroke-width="2" />
                {{ t("frontend.plannings.share.times_in", { zone }) }}
            </span>
        </div>

        <AppNoData v-if="!calendars.length" :message="t('frontend.plannings.share.nothing')" />

        <!-- No `add-on`, no `move-event`, no `open-event`: the grids emit them and
             nothing listens. Every event also arrives `readOnly`, so no drag starts
             in the first place - a gesture that cannot finish reads as broken. -->
        <CalendarMonth
            v-else-if="'month' === effectiveView"
            :cells="cells"
            :events="visibleEvents"
            :reminders="visibleReminders"
            :compact="narrow"
        />
        <CalendarAgenda
            v-else-if="'agenda' === effectiveView"
            :cells="cells"
            :events="visibleEvents"
            :reminders="visibleReminders"
        />
        <CalendarTimeGrid
            v-else
            :anchor="anchor"
            :view="effectiveView"
            :events="visibleEvents"
            :reminders="visibleReminders"
        />
    </div>
</template>
