<script setup>
/**
 * The calendar screen: which calendars, which month, what is on it.
 *
 * Thin on purpose. The date arithmetic is `monthGrid.js`, the month's state and
 * its fetching are `usePlanningCalendar`, the grid is `CalendarMonth` and one
 * event is `EventModal`. What is left here is the toolbar, the sidebar, and
 * turning a click into a request.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarPlus, ChevronLeft, ChevronRight } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import CalendarMonth from "./components/CalendarMonth.vue";
import EventModal from "./components/EventModal.vue";
import { usePlanningCalendar } from "./composables/usePlanningCalendar.js";

const props = defineProps({
    calendars: { type: Array, default: () => [] },
    eventsPath: { type: String, required: true },
    createEventPath: { type: String, required: true },
    updateEventPathTemplate: { type: String, required: true },
    deleteEventPathTemplate: { type: String, required: true },
});

const { t, d } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();

const {
    calendars,
    visibleEvents,
    hidden,
    loading,
    year,
    month,
    cells,
    load,
    goToMonth,
    goToToday,
    toggleCalendar,
} = usePlanningCalendar(props);

const monthLabel = computed(() =>
    d(new Date(year.value, month.value, 1), { month: "long", year: "numeric" }),
);

const openEvent = ref(null);
const editing = ref(false);
const errors = ref({});
const saving = ref(false);

function view(event) {
    openEvent.value = event;
    editing.value = false;
    errors.value = {};
}

function create() {
    // An empty object rather than null: the modal opens on `event !== null`, and
    // a new event has no id yet.
    openEvent.value = {};
    editing.value = true;
    errors.value = {};
}

function close() {
    openEvent.value = null;
    editing.value = false;
}

async function save(form) {
    saving.value = true;
    errors.value = {};

    try {
        const id = openEvent.value?.id;
        const path = id
            ? props.updateEventPathTemplate.replace("__id__", String(id))
            : props.createEventPath;

        const data = await request(path, form);

        // `useRequest` has already said something went wrong; what it cannot say
        // is which field, so the form keeps the modal open and shows them.
        if (!data) return;
        if (data.errors) {
            errors.value = data.errors;

            return;
        }

        close();
        await load();
    } finally {
        saving.value = false;
    }
}

async function remove(event) {
    const data = await request(props.deleteEventPathTemplate.replace("__id__", String(event.id)));
    if (!data) return;

    close();
    await load();
}
</script>

<template>
    <div class="relative grid grid-cols-1 lg:grid-cols-[13rem_1fr] gap-4">
        <AppLoader :active="loading" />

        <aside class="space-y-4">
            <AppButton
                v-if="can('planning.events.create') && calendars.length"
                variant="primary"
                size="md"
                class="w-full"
                v-on:click="create"
            >
                <CalendarPlus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.plannings.events.new") }}
            </AppButton>

            <div class="bg-surface border border-line rounded-xl p-4 space-y-2.5">
                <p class="text-2xs font-semibold uppercase tracking-wider text-muted">
                    {{ t("backend.plannings.calendars") }}
                </p>

                <AppNoData v-if="!calendars.length" :message="t('backend.plannings.empty')" />

                <!-- A calendar folded away is a display decision, so it toggles
                     without a round trip and without touching the URL: which of
                     your own calendars you have hidden is not something you send
                     anyone. -->
                <button
                    v-for="calendar in calendars"
                    :key="calendar.id"
                    type="button"
                    class="flex w-full items-center gap-2 text-left text-sm transition-opacity"
                    :class="hidden.has(calendar.id) ? 'opacity-40' : ''"
                    v-on:click="toggleCalendar(calendar.id)"
                >
                    <span
                        class="w-3 h-3 rounded shrink-0"
                        :style="hidden.has(calendar.id)
                            ? { border: '1.5px solid var(--th-muted)' }
                            : { backgroundColor: `var(--chart-cat-${calendar.colourSlot})` }"
                    />
                    <span class="text-secondary truncate">{{ calendar.name }}</span>
                    <span class="ml-auto text-2xs text-muted tabular-nums shrink-0">{{ calendar.eventCount }}</span>
                </button>
            </div>
        </aside>

        <div class="min-w-0 space-y-3">
            <div class="flex items-center gap-2">
                <AppIconButton :title="t('shared.common.previous')" v-on:click="goToMonth(-1)">
                    <ChevronLeft class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton :title="t('shared.common.next')" v-on:click="goToMonth(1)">
                    <ChevronRight class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
                <!-- Capitalised by the locale's own rules, so "août 2026" reads
                     as a heading without a CSS transform that would also shout at
                     a language where it should not. -->
                <h2 class="text-base font-semibold text-primary first-letter:uppercase">{{ monthLabel }}</h2>
                <AppButton variant="ghost" size="sm" class="ml-auto" v-on:click="goToToday">
                    {{ t("backend.plannings.today") }}
                </AppButton>
            </div>

            <CalendarMonth :cells="cells" :events="visibleEvents" v-on:open-event="view" />
        </div>

        <EventModal
            :event="openEvent"
            :editing="editing"
            :calendars="calendars"
            :errors="errors"
            :saving="saving"
            v-on:close="close"
            v-on:edit="editing = true"
            v-on:save="save"
            v-on:delete="remove"
        />
    </div>
</template>
