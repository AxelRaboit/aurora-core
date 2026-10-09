<script setup>
/**
 * The journal's calendar (09/10/2026), as Obsidian's calendar and Craft's
 * daily notes have it: today's note at hand, and every day of the month with
 * a dot when its note exists. A click opens that day's note, writing it the
 * first time.
 */
import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import AppButton from "@shared/components/action/AppButton.vue";
import { dayKey, monthGrid } from "@/shared/composables/calendar/monthGrid.js";

const props = defineProps({
    /** `(month: 'YYYY-MM') => Promise<string[]>`, the days that have their note. */
    loadDays: { type: Function, required: true },
});

const emit = defineEmits(["open"]);

const { t, locale } = useI18n();
const today = new Date();
const shown = ref({ year: today.getFullYear(), month: today.getMonth() });
const days = ref(new Set());

const monthKey = computed(() => `${shown.value.year}-${String(shown.value.month + 1).padStart(2, "0")}`);
const cells = computed(() => monthGrid(shown.value.year, shown.value.month));
const title = computed(() =>
    new Intl.DateTimeFormat(String(locale.value ?? "fr"), { month: "long", year: "numeric" }).format(new Date(shown.value.year, shown.value.month, 1)),
);
const weekdays = computed(() =>
    cells.value.slice(0, 7).map((cell) => new Intl.DateTimeFormat(String(locale.value ?? "fr"), { weekday: "narrow" }).format(cell.date)),
);

async function refresh() {
    const wanted = monthKey.value;
    const found = await props.loadDays(wanted);
    if (wanted === monthKey.value) days.value = new Set(found ?? []);
}

onMounted(refresh);
watch(monthKey, refresh);

function move(step) {
    const next = new Date(shown.value.year, shown.value.month + step, 1);
    shown.value = { year: next.getFullYear(), month: next.getMonth() };
}
</script>

<template>
    <div data-note-journal class="flex w-72 flex-col gap-2 rounded-lg border border-line bg-surface p-3 shadow-xl">
        <AppButton
            variant="primary"
            size="sm"
            class="w-full justify-center"
            data-note-journal-today
            v-on:click="emit('open', dayKey(today))"
        >
            {{ t('notes.markdown.daily.today') }}
        </AppButton>
        <div class="flex items-center justify-between">
            <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-primary" :aria-label="t('notes.markdown.daily.previous')" v-on:click="move(-1)">
                <ChevronLeft class="h-4 w-4" :stroke-width="2" />
            </button>
            <span class="text-sm font-medium capitalize text-primary">{{ title }}</span>
            <button type="button" class="rounded p-1 text-muted hover:bg-surface-2 hover:text-primary" :aria-label="t('notes.markdown.daily.next')" v-on:click="move(1)">
                <ChevronRight class="h-4 w-4" :stroke-width="2" />
            </button>
        </div>
        <div class="grid grid-cols-7 gap-0.5 text-center">
            <span v-for="(weekday, index) in weekdays" :key="`weekday-${index}`" class="text-2xs uppercase text-muted">{{ weekday }}</span>
            <button
                v-for="cell in cells"
                :key="cell.key"
                type="button"
                :data-note-journal-day="cell.key"
                class="relative flex h-8 items-center justify-center rounded-md text-xs transition-colors hover:bg-surface-2"
                :class="[
                    cell.inMonth ? 'text-primary' : 'text-muted/50',
                    dayKey(today) === cell.key ? 'font-semibold ring-1 ring-accent-500' : '',
                ]"
                v-on:click="emit('open', cell.key)"
            >
                {{ cell.dayOfMonth }}
                <span v-if="days.has(cell.key)" class="absolute bottom-1 h-1 w-1 rounded-full bg-accent-500" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
