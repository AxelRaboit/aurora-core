<script setup>
/**
 * One day's contents, as a list.
 *
 * What a phone shows under the month grid, because a month cell there is about
 * fifty pixels wide - enough for a day number and a few dots, and nothing like
 * enough for a title. Both Google and Apple answer this the same way: the grid
 * says which days have something, and a list below says what.
 *
 * Also used on a wide screen? No - deliberately not. The wide grid already shows
 * titles in the cells, and a list repeating them would say everything twice.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Check, Plus } from "lucide-vue-next";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { itemsOn } from "../composables/monthGrid.js";

const props = defineProps({
    /** The day being listed. */
    date: { type: Date, required: true },
    events: { type: Array, required: true },
    reminders: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
});

const emit = defineEmits(["open-event", "open-reminder", "toggle-reminder", "add"]);

const { t, d } = useI18n();

const items = computed(() => itemsOn(props.date, props.events, props.reminders));

const heading = computed(() =>
    d(props.date, { weekday: "long", day: "numeric", month: "long" }),
);

function timeOf(at) {
    return d(at, { hour: "2-digit", minute: "2-digit" });
}
</script>

<template>
    <div class="bg-surface border border-line rounded-xl">
        <div class="flex items-center gap-2 border-b border-line px-3 py-2">
            <p class="min-w-0 truncate text-sm font-semibold text-primary first-letter:uppercase">
                {{ heading }}
            </p>
            <!-- Creating from here rather than by tapping a cell: on a phone the
                 tap on a cell has to mean "show me this day", so the gesture that
                 makes something needs its own control. -->
            <AppIconButton
                v-if="canCreate"
                class="ml-auto -my-1"
                :title="t('backend.plannings.events.new')"
                v-on:click="emit('add', date)"
            >
                <Plus class="w-4 h-4" :stroke-width="2" />
            </AppIconButton>
        </div>

        <div class="p-3">
            <AppNoData v-if="!items.length" :message="t('backend.plannings.nothing_on_day')" />

            <div v-else class="flex flex-col gap-2">
                <div
                    v-for="entry in items"
                    :key="`${entry.kind}-${entry.item.id}`"
                    class="flex items-start gap-2 min-w-0"
                >
                    <!-- A reminder gets a checkbox, an event a colour bar. The
                         shapes differ because the things do: one is finished by
                         you, the other by time passing. -->
                    <button
                        v-if="'reminder' === entry.kind"
                        type="button"
                        class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border transition-colors cursor-pointer"
                        :class="entry.item.completed ? 'border-transparent' : 'border-secondary'"
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
                        class="flex min-w-0 flex-1 flex-col items-start text-left cursor-pointer"
                        v-on:click="emit(
                            'reminder' === entry.kind ? 'open-reminder' : 'open-event',
                            entry.item,
                        )"
                    >
                        <span
                            class="w-full truncate text-sm"
                            :class="'reminder' === entry.kind && entry.item.completed
                                ? 'line-through text-muted'
                                : 'text-primary'"
                        >{{ entry.item.title }}</span>
                        <span
                            class="text-2xs tabular-nums"
                            :class="'reminder' === entry.kind && entry.item.overdue && !entry.item.completed
                                ? 'text-red-500'
                                : 'text-muted'"
                        >
                            {{ entry.whole ? t("backend.plannings.events.all_day") : timeOf(entry.at) }}
                            <template v-if="entry.item.location"> · {{ entry.item.location }}</template>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
