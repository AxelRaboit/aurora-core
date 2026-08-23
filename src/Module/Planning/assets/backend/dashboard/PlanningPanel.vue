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

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
});

const { t, d } = useI18n();

const overdue = computed(() => props.stats.overdue ?? 0);

const upcoming = computed(() =>
    (props.stats.upcoming ?? []).map((row) => ({
        ...row,
        when: row.allDay
            ? d(new Date(row.at), { weekday: "short", day: "numeric", month: "short" })
            : d(new Date(row.at), {
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
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-surface border border-line rounded-xl p-4 flex items-center gap-3">
                <CalendarDays class="w-4 h-4 shrink-0 text-muted" :stroke-width="2" />
                <div class="min-w-0">
                    <p class="text-lg font-semibold text-primary tabular-nums leading-none">
                        {{ stats.calendars ?? 0 }}
                    </p>
                    <p class="mt-1 text-2xs uppercase tracking-wider text-muted">
                        {{ t("backend.plannings.calendars") }}
                    </p>
                </div>
            </div>

            <!-- Tinted only when there is something late. A panel that is red at
                 rest teaches the reader to ignore red. -->
            <div
                class="border rounded-xl p-4 flex items-center gap-3"
                :class="overdue > 0 ? 'bg-red-500/10 border-red-500/30' : 'bg-surface border-line'"
            >
                <AlarmClock
                    class="w-4 h-4 shrink-0"
                    :class="overdue > 0 ? 'text-red-500' : 'text-muted'"
                    :stroke-width="2"
                />
                <div class="min-w-0">
                    <p
                        class="text-lg font-semibold tabular-nums leading-none"
                        :class="overdue > 0 ? 'text-red-500' : 'text-primary'"
                    >
                        {{ overdue }}
                    </p>
                    <p class="mt-1 text-2xs uppercase tracking-wider text-muted">
                        {{ t("backend.plannings.reminders.overdue") }}
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-surface border border-line rounded-xl p-4 space-y-3">
            <div class="flex items-center gap-2">
                <p class="text-2xs font-semibold uppercase tracking-wider text-muted">
                    {{ t("backend.plannings.upcoming") }}
                </p>
                <a
                    v-if="stats.path"
                    :href="stats.path"
                    class="ml-auto text-xs text-accent-600 hover:underline"
                >{{ t("backend.plannings.open_calendar") }}</a>
            </div>

            <AppNoData v-if="!upcoming.length" :message="t('backend.plannings.nothing_upcoming')" />

            <div v-else class="flex flex-col gap-2">
                <div
                    v-for="row in upcoming"
                    :key="`${row.kind}-${row.id}`"
                    class="flex items-baseline gap-2 min-w-0"
                >
                    <span
                        class="w-1.5 h-1.5 rounded-full shrink-0"
                        :style="{ backgroundColor: `var(--chart-cat-${row.colourSlot})` }"
                    />
                    <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ row.title }}</span>
                    <span class="shrink-0 text-2xs text-muted tabular-nums">{{ row.when }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
