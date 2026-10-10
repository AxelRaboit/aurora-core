<script setup>
/**
 * The calendar's dashboard panel: what is coming up, and what is late.
 *
 * Not the same shape as Editorial's or the GED's, and that is deliberate rather
 * than careless. Those answer "how much does the site hold", so four figures and
 * a composition is right. A calendar's useful answer is a list - nobody opens a
 * dashboard to learn they own four calendars - so the figures are two, and the
 * space goes to the next few things.
 *
 * Every field is defaulted: a dashboard is not the place to throw because a
 * figure was missing.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { AlarmClock, CalendarDays } from "lucide-vue-next";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppSectionCard from "@/shared/components/display/AppSectionCard.vue";
import AppStatTile from "@/shared/components/display/AppStatTile.vue";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
});

const { t, d: formatDate } = useI18n();

const overdue = computed(() => props.stats.overdue ?? 0);

const upcoming = computed(() =>
    (props.stats.upcoming ?? []).map((row) => ({
        ...row,
        when: row.allDay
            ? formatDate(new Date(row.at), { weekday: "short", day: "numeric", month: "short" })
            : formatDate(new Date(row.at), {
                weekday: "short",
                day: "numeric",
                month: "short",
                hour: "2-digit",
                minute: "2-digit",
            }),
    })),
);
</script>

<template>
    <div class="aurora-stack">
        <!-- The house tile, two of them across the full width: the figures
             read like every other panel's, and the list below keeps the room. -->
        <div class="grid grid-cols-2 gap-3">
            <AppStatTile
                :icon="CalendarDays"
                :label="t('suite.plannings.calendars')"
                :value="stats.calendars ?? 0"
            />
            <!-- Red only when there is something late. A panel that is red at
                 rest teaches the reader to ignore red. -->
            <AppStatTile
                :icon="AlarmClock"
                :label="t('suite.plannings.reminders.overdue')"
                :value="overdue"
                :tone="overdue > 0 ? 'danger' : 'default'"
            />
        </div>

        <AppSectionCard :title="t('suite.plannings.upcoming')">
            <template v-if="stats.path" #meta>
                <a
                    :href="stats.path"
                    class="-my-1 inline-flex min-h-7 items-center rounded px-1 py-1 text-xs font-medium text-accent hover:underline"
                >{{ t("suite.plannings.open_calendar") }}</a>
            </template>

            <AppNoData v-if="!upcoming.length" :message="t('suite.plannings.nothing_upcoming')" />

            <div v-else class="flex flex-col gap-1">
                <!-- Each row opens its day in the calendar. The date in the
                     key: two occurrences of a series have the same id. -->
                <a
                    v-for="row in upcoming"
                    :key="`${row.kind}-${row.id}-${row.at}`"
                    :href="row.path"
                    class="-mx-2 flex min-w-0 items-baseline gap-2.5 rounded-md px-2 py-1.5 transition-colors hover:bg-surface-2"
                >
                    <span
                        class="h-2 w-2 shrink-0 self-center rounded-full"
                        :style="{ backgroundColor: `var(--chart-cat-${row.colourSlot})` }"
                    />
                    <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ row.title }}</span>
                    <span class="shrink-0 text-xs text-secondary tabular-nums">{{ row.when }}</span>
                </a>
            </div>
        </AppSectionCard>
    </div>
</template>
