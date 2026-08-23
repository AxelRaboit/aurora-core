<script setup>
/**
 * The calendar screen: which calendars, which month, what is on it.
 *
 * Thin on purpose. The date arithmetic is `monthGrid.js`, the month's state and
 * its fetching are `usePlanningCalendar`, the grid is `CalendarMonth` and one
 * event is `EventModal`. What is left here is the toolbar, the sidebar, and
 * turning a click into a request.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { BellPlus, CalendarDays, CalendarPlus, ChevronLeft, ChevronRight, Pencil, Plus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import CalendarModal from "./components/CalendarModal.vue";
import CalendarDayList from "./components/CalendarDayList.vue";
import CalendarSidebar from "./components/CalendarSidebar.vue";
import CalendarMonth from "./components/CalendarMonth.vue";
import CalendarTimeGrid from "./components/CalendarTimeGrid.vue";
import ReminderModal from "./components/ReminderModal.vue";
import EventModal from "./components/EventModal.vue";
import { usePlanningCalendar } from "./composables/usePlanningCalendar.js";
import { defaultTimeOn, draftAt } from "./composables/timeGrid.js";

const props = defineProps({
    calendars: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    eventsPath: { type: String, required: true },
    createCalendarPath: { type: String, required: true },
    updateCalendarPathTemplate: { type: String, required: true },
    deleteCalendarPathTemplate: { type: String, required: true },
    createEventPath: { type: String, required: true },
    createReminderPath: { type: String, required: true },
    updateReminderPathTemplate: { type: String, required: true },
    deleteReminderPathTemplate: { type: String, required: true },
    toggleReminderPathTemplate: { type: String, required: true },
    updateEventPathTemplate: { type: String, required: true },
    deleteEventPathTemplate: { type: String, required: true },
});

const { t, d } = useI18n();
const { can } = usePrivileges();
const { request } = useRequest();

const {
    calendars,
    visibleEvents,
    visibleReminders,
    countsByCalendar,
    hidden,
    loading,
    year,
    month,
    view,
    effectiveView,
    narrow,
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

/**
 * Whether the calendar list is showing as a sheet.
 *
 * Only ever true on a narrow viewport, where the sidebar has nowhere to be: at
 * 375px a 13rem column leaves 167 pixels for a seven-day grid. Both Google and
 * Apple put this behind a button on a phone.
 */
const sheetOpen = ref(false);

/**
 * The day the phone's list is showing.
 *
 * Only meaningful in the compact month view. Defaults to today, and follows the
 * month when the reader pages so the list is never about a day the grid no longer
 * shows.
 */
const selectedDay = ref(new Date());

watch([year, month], () => {
    // The first of the month, unless today is in it - paging to a month you are
    // not in and landing on "the 23rd" would be arbitrary.
    const today = new Date();
    selectedDay.value =
        today.getFullYear() === year.value && today.getMonth() === month.value
            ? today
            : new Date(year.value, month.value, 1);
});

const openCalendar = ref(null);
const calendarErrors = ref({});
const savingCalendar = ref(false);

const canManageCalendars = computed(() => can("planning.calendars.manage"));

/** An event needs a calendar to live in, so an empty sidebar closes this too. */
const canCreateEvents = computed(() => can("planning.events.create") && calendars.value.length > 0);

function createCalendar() {
    // `{}` and not null: the modal opens on `calendar !== null`, and a new
    // calendar has no id yet.
    openCalendar.value = {};
    calendarErrors.value = {};
    // The sheet has to go, or the modal opens behind it on a phone.
    sheetOpen.value = false;
}

function editCalendar(calendar) {
    openCalendar.value = calendar;
    calendarErrors.value = {};
    sheetOpen.value = false;
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

function create(draft = {}) {
    // The same gate the button carries. A click on the grid is a second way in,
    // and without this it would open a form whose save is refused - or one with
    // no calendar to put the event in.
    if (!canCreateEvents.value) {
        return;
    }

    // An empty object rather than null: the modal opens on `event !== null`, and
    // a new event has no id yet. A click on the grid hands in a draft with its
    // two instants, so the form opens already on the day that was pointed at.
    openEvent.value = draft;
    editing.value = true;
    errors.value = {};
    sheetOpen.value = false;
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

const openReminder = ref(null);
const reminderErrors = ref({});
const savingReminder = ref(false);

function createReminder(draft = {}) {
    if (!canCreateEvents.value) {
        return;
    }

    openReminder.value = draft;
    reminderErrors.value = {};
    sheetOpen.value = false;
}

function editReminder(reminder) {
    openReminder.value = reminder;
    reminderErrors.value = {};
}

function closeReminder() {
    openReminder.value = null;
}

async function saveReminder(form) {
    savingReminder.value = true;
    reminderErrors.value = {};

    try {
        const id = openReminder.value?.id;
        const path = id
            ? props.updateReminderPathTemplate.replace("__id__", String(id))
            : props.createReminderPath;

        const data = await request(path, form);

        if (!data) return;
        if (data.errors) {
            reminderErrors.value = data.errors;

            return;
        }

        closeReminder();
        await load();
    } finally {
        savingReminder.value = false;
    }
}

async function removeReminderItem(reminder) {
    const data = await request(props.deleteReminderPathTemplate.replace("__id__", String(reminder.id)));
    if (!data) return;

    closeReminder();
    await load();
}

/**
 * Ticking one off from the grid.
 *
 * Reloads rather than patching the row in place, because `overdue` is computed on
 * the server - a client flipping `completed` itself would leave a reminder struck
 * through and still red.
 */
async function toggleReminderItem(reminder) {
    const data = await request(props.toggleReminderPathTemplate.replace("__id__", String(reminder.id)));
    if (!data) return;

    await load();
}

/**
 * Starts an event on the day the phone's list is showing.
 *
 * The same draft a cell click builds on a wide screen, at the constant hour a day
 * with no time in it gets.
 */
function createOnDay(date) {
    create(draftAt(defaultTimeOn(date)));
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

        <!-- The column on a wide screen. On a phone the same component is drawn
             inside the sheet below, because a 13rem sidebar there would leave 167
             pixels for a seven-day grid. -->
        <CalendarSidebar
            class="hidden lg:block"
            :calendars="calendars"
            :hidden="hidden"
            :counts-by-calendar="countsByCalendar"
            :can-create-events="canCreateEvents"
            :can-manage-calendars="canManageCalendars"
            v-on:create-event="create"
            v-on:create-reminder="createReminder"
            v-on:create-calendar="createCalendar"
            v-on:edit-calendar="editCalendar"
            v-on:toggle-calendar="toggleCalendar"
        />

        <div class="min-w-0 space-y-3">
            <!-- Two rows below `md`. Everything on one line needs about 366
                 pixels of controls at 375 of viewport, so the label truncated to
                 nothing and the switcher wrapped under the chevrons. -->
            <div class="flex flex-wrap items-center gap-2">
                <AppIconButton
                    class="lg:hidden"
                    :title="t('backend.plannings.calendars')"
                    v-on:click="sheetOpen = true"
                >
                    <CalendarDays class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton :title="t('shared.common.previous')" v-on:click="go(-1)">
                    <ChevronLeft class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton :title="t('shared.common.next')" v-on:click="go(1)">
                    <ChevronRight class="w-4 h-4" :stroke-width="2" />
                </AppIconButton>
                <!-- Capitalised by the locale's own rules, so "août 2026" reads
                     as a heading without a CSS transform that would also shout at
                     a language where it should not. -->
                <h2 class="min-w-0 truncate text-sm font-semibold text-primary first-letter:uppercase sm:text-base">
                    {{ rangeLabel }}
                </h2>

                <div class="flex w-full items-center gap-2 sm:ml-auto sm:w-auto">
                    <!-- A segmented row and not a select: three mutually
                         exclusive choices where the current one has to be
                         readable at a glance, which is what a calendar's reader
                         checks before trusting what they are looking at. -->
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

            <CalendarMonth
                v-if="'month' === effectiveView"
                :cells="cells"
                :events="visibleEvents"
                :reminders="visibleReminders"
                :compact="narrow"
                :selected="narrow ? selectedDay : null"
                v-on:open-event="viewEvent"
                v-on:open-reminder="editReminder"
                v-on:toggle-reminder="toggleReminderItem"
                v-on:add-on="create"
                v-on:select-day="selectedDay = $event"
            />

            <!-- The list under the compact grid. The grid says which days have
                 something; this says what. -->
            <CalendarDayList
                v-if="'month' === effectiveView && narrow"
                :date="selectedDay"
                :events="visibleEvents"
                :reminders="visibleReminders"
                :can-create="canCreateEvents"
                v-on:open-event="viewEvent"
                v-on:open-reminder="editReminder"
                v-on:toggle-reminder="toggleReminderItem"
                v-on:add="createOnDay"
            />
            <CalendarTimeGrid
                v-else
                :anchor="anchor"
                :view="effectiveView"
                :events="visibleEvents"
                :reminders="visibleReminders"
                v-on:open-event="viewEvent"
                v-on:open-reminder="editReminder"
                v-on:toggle-reminder="toggleReminderItem"
                v-on:add-on="create"
            />
        </div>

        <!-- The sidebar as a sheet. `AppModal` rather than a hand-rolled drawer:
             it already handles the overlay, escape, the browser's back button and
             locking the page behind it, and getting those wrong is what makes a
             phone sheet feel broken. -->
        <AppModal
            :show="sheetOpen"
            max-width="sm"
            mobile-fullscreen
            :title="t('backend.plannings.calendars')"
            v-on:close="sheetOpen = false"
        >
            <CalendarSidebar
                :calendars="calendars"
                :hidden="hidden"
                :counts-by-calendar="countsByCalendar"
                :can-create-events="canCreateEvents"
                :can-manage-calendars="canManageCalendars"
                v-on:create-event="create"
                v-on:create-reminder="createReminder"
                v-on:create-calendar="createCalendar"
                v-on:edit-calendar="editCalendar"
                v-on:toggle-calendar="toggleCalendar"
            />
        </AppModal>

        <ReminderModal
            :reminder="openReminder"
            :calendars="calendars"
            :errors="reminderErrors"
            :saving="savingReminder"
            v-on:close="closeReminder"
            v-on:save="saveReminder"
            v-on:delete="removeReminderItem"
        />

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
