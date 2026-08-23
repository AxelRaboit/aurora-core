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
import { CalendarPlus, ChevronLeft, ChevronRight, Pencil, Plus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import CalendarModal from "./components/CalendarModal.vue";
import CalendarMonth from "./components/CalendarMonth.vue";
import CalendarTimeGrid from "./components/CalendarTimeGrid.vue";
import EventModal from "./components/EventModal.vue";
import { usePlanningCalendar } from "./composables/usePlanningCalendar.js";

const props = defineProps({
    calendars: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    eventsPath: { type: String, required: true },
    createCalendarPath: { type: String, required: true },
    updateCalendarPathTemplate: { type: String, required: true },
    deleteCalendarPathTemplate: { type: String, required: true },
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
    view,
    anchor,
    cells,
    days,
    load,
    go,
    goToToday,
    setView,
    toggleCalendar,
    upsertCalendar,
    removeCalendar,
} = usePlanningCalendar(props);

/**
 * What range is on screen, in words.
 *
 * Three shapes, because the reader needs a different amount of it in each view:
 * the month says the month, the day says the whole date, and the week says the
 * span - shortened to "24 - 30 août" when both ends share a month, since
 * repeating it is noise.
 */
const rangeLabel = computed(() => {
    if ("month" === view.value) {
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
    ["day", "week", "month"].map((value) => ({
        value,
        label: t(`backend.plannings.views.${value}`),
    })),
);

const openCalendar = ref(null);
const calendarErrors = ref({});
const savingCalendar = ref(false);

const canManageCalendars = computed(() => can("planning.calendars.manage"));

function createCalendar() {
    // `{}` and not null: the modal opens on `calendar !== null`, and a new
    // calendar has no id yet.
    openCalendar.value = {};
    calendarErrors.value = {};
}

function editCalendar(calendar) {
    openCalendar.value = calendar;
    calendarErrors.value = {};
}

function closeCalendar() {
    openCalendar.value = null;
}

async function saveCalendar(form) {
    savingCalendar.value = true;
    calendarErrors.value = {};

    try {
        const id = openCalendar.value?.id;
        const path = id
            ? props.updateCalendarPathTemplate.replace("__id__", String(id))
            : props.createCalendarPath;

        const data = await request(path, form);

        if (!data) return;
        if (data.errors) {
            calendarErrors.value = data.errors;

            return;
        }

        upsertCalendar(data.calendar);
        closeCalendar();
        // Reloaded because a calendar's colour is drawn on every one of its
        // events, and renaming it changes what the popover says.
        await load();
    } finally {
        savingCalendar.value = false;
    }
}

async function removeCalendarAndItsEvents(calendar) {
    const data = await request(props.deleteCalendarPathTemplate.replace("__id__", String(calendar.id)));
    if (!data) return;

    removeCalendar(calendar.id);
    closeCalendar();
    await load();
}

const openEvent = ref(null);
const editing = ref(false);
const errors = ref({});
const saving = ref(false);

function viewEvent(event) {
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
                <div class="flex items-center gap-2">
                    <p class="text-2xs font-semibold uppercase tracking-wider text-muted">
                        {{ t("backend.plannings.calendars") }}
                    </p>
                    <AppIconButton
                        v-if="canManageCalendars"
                        class="ml-auto -my-1"
                        :title="t('backend.plannings.new_calendar')"
                        v-on:click="createCalendar"
                    >
                        <Plus class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <!-- The empty state used to be the end of the road: no calendar
                     meant no way to make one, and the "new event" button below
                     is hidden without one. -->
                <template v-if="!calendars.length">
                    <AppNoData :message="t('backend.plannings.empty')" />
                    <AppButton
                        v-if="canManageCalendars"
                        variant="primary"
                        size="sm"
                        class="w-full"
                        v-on:click="createCalendar"
                    >
                        <CalendarPlus class="w-4 h-4" :stroke-width="2" />
                        {{ t("backend.plannings.new_calendar") }}
                    </AppButton>
                </template>

                <!-- A calendar folded away is a display decision, so it toggles
                     without a round trip and without touching the URL: which of
                     your own calendars you have hidden is not something you send
                     anyone.

                     A row, not a button, because it now holds two actions: the
                     name toggles, the pencil edits. A button inside a button is
                     invalid HTML and the inner one never fires. -->
                <div
                    v-for="calendar in calendars"
                    :key="calendar.id"
                    class="group flex items-center gap-2 text-sm transition-opacity"
                    :class="hidden.has(calendar.id) ? 'opacity-40' : ''"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-2 text-left cursor-pointer"
                        :aria-pressed="!hidden.has(calendar.id)"
                        v-on:click="toggleCalendar(calendar.id)"
                    >
                        <span
                            class="w-3 h-3 rounded shrink-0"
                            :style="hidden.has(calendar.id)
                                ? { border: '1.5px solid var(--th-muted)' }
                                : { backgroundColor: `var(--chart-cat-${calendar.colourSlot})` }"
                        />
                        <span class="text-secondary truncate">{{ calendar.name }}</span>
                    </button>

                    <span class="text-2xs text-muted tabular-nums shrink-0 group-hover:hidden">
                        {{ calendar.eventCount }}
                    </span>
                    <!-- Takes the count's place on hover rather than sitting
                         beside it, so the row does not change width and the
                         names stay lined up. -->
                    <button
                        v-if="canManageCalendars"
                        type="button"
                        class="hidden shrink-0 cursor-pointer text-muted hover:text-primary group-hover:block"
                        :title="t('backend.plannings.edit_calendar')"
                        v-on:click="editCalendar(calendar)"
                    >
                        <Pencil class="w-3.5 h-3.5" :stroke-width="2" />
                    </button>
                </div>
            </div>
        </aside>

        <div class="min-w-0 space-y-3">
            <div class="flex items-center gap-2">
                <AppIconButton :title="t('shared.common.previous')" v-on:click="go(-1)">
                    <ChevronLeft class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton :title="t('shared.common.next')" v-on:click="go(1)">
                    <ChevronRight class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
                <!-- Capitalised by the locale's own rules, so "août 2026" reads
                     as a heading without a CSS transform that would also shout at
                     a language where it should not. -->
                <h2 class="text-base font-semibold text-primary first-letter:uppercase">{{ rangeLabel }}</h2>

                <div class="ml-auto flex items-center gap-2">
                    <!-- A segmented row and not a select: three mutually
                         exclusive choices where the current one has to be
                         readable at a glance, which is what a calendar's reader
                         checks before trusting what they are looking at. -->
                    <div class="flex rounded-lg border border-line overflow-hidden">
                        <button
                            v-for="option in viewOptions"
                            :key="option.value"
                            type="button"
                            class="px-2.5 py-1 text-xs transition-colors cursor-pointer border-r border-line last:border-r-0"
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

            <CalendarMonth
                v-if="'month' === view"
                :cells="cells"
                :events="visibleEvents"
                v-on:open-event="viewEvent"
            />
            <CalendarTimeGrid
                v-else
                :anchor="anchor"
                :view="view"
                :events="visibleEvents"
                v-on:open-event="viewEvent"
            />
        </div>

        <CalendarModal
            :calendar="openCalendar"
            :calendars="calendars"
            :timezones="timezones"
            :errors="calendarErrors"
            :saving="savingCalendar"
            v-on:close="closeCalendar"
            v-on:save="saveCalendar"
            v-on:delete="removeCalendarAndItsEvents"
        />

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
