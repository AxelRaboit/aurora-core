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
import CalendarAgenda from "./components/CalendarAgenda.vue";
import CalendarSidebar from "./components/CalendarSidebar.vue";
import CalendarMonth from "./components/CalendarMonth.vue";
import CalendarTimeGrid from "./components/CalendarTimeGrid.vue";
import RecurrenceScopeModal from "./components/RecurrenceScopeModal.vue";
import ReminderModal from "./components/ReminderModal.vue";
import EventModal from "./components/EventModal.vue";
import { usePlanningCalendar } from "./composables/usePlanningCalendar.js";
import { defaultTimeOn, draftAt } from "./composables/timeGrid.js";
import { usePlanningShortcuts } from "./composables/usePlanningShortcuts.js";

const props = defineProps({
    calendars: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    people: { type: Array, default: () => [] },
    currentUserId: { type: [Number, null], default: null },
    respondEventPathTemplate: { type: String, required: true },
    eventsPath: { type: String, required: true },
    createCalendarPath: { type: String, required: true },
    feedCalendarPathTemplate: { type: String, required: true },
    revokeFeedCalendarPathTemplate: { type: String, required: true },
    updateCalendarPathTemplate: { type: String, required: true },
    deleteCalendarPathTemplate: { type: String, required: true },
    createEventPath: { type: String, required: true },
    moveEventPathTemplate: { type: String, required: true },
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
    usesMonthRange,
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

/**
 * The feed address, held only between the request that created it and the modal
 * closing.
 *
 * Not kept in the calendar list: that payload is on every page, and a live
 * credential does not belong there for the sake of showing it a second time.
 */
const feedUrl = ref("");
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
    feedUrl.value = "";
    sheetOpen.value = false;
}

function closeCalendar() {
    openCalendar.value = null;
    feedUrl.value = "";
}

async function publishFeed(calendar) {
    const data = await request(
        props.feedCalendarPathTemplate.replace("__id__", String(calendar.id)),
    );
    if (!data) return;

    upsertCalendar(data.calendar);
    openCalendar.value = data.calendar;
    feedUrl.value = data.feedUrl ?? "";
}

async function revokeFeed(calendar) {
    const data = await request(
        props.revokeFeedCalendarPathTemplate.replace("__id__", String(calendar.id)),
    );
    if (!data) return;

    upsertCalendar(data.calendar);
    openCalendar.value = data.calendar;
    feedUrl.value = "";
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

/**
 * A write to a series, held while the reader is asked what it applies to.
 *
 * One ref rather than three, so a pending question cannot be half-formed: either
 * something is waiting on an answer or nothing is.
 */
const pendingScope = ref(null);

/**
 * Whether an appearance belongs to a series and therefore needs the question.
 *
 * `occurrenceAt` is what makes it a generated appearance rather than a row. A
 * detached occurrence has a master but no rule of its own, so it is a single event
 * and asking about it would offer three answers to a question with one.
 */
function needsScope(event) {
    return Boolean(event?.recurring && event?.occurrenceAt);
}

function askScope(kind, intent, payload) {
    pendingScope.value = { kind, intent, payload };
}

function cancelScope() {
    pendingScope.value = null;
}

/**
 * Runs the held write, now that the scope is known.
 *
 * The scope and the occurrence travel in the same body as the fields, which is why
 * all three writes take them the same way: the server resolves which row to touch,
 * and nothing here has to know whether a row was detached or a series split.
 */
async function confirmScope(scope) {
    const pending = pendingScope.value;
    pendingScope.value = null;

    if (null === pending) {
        return;
    }

    const scoped = { ...pending.payload, scope };

    if ("save" === pending.kind) {
        await sendSave(scoped);
    } else if ("delete" === pending.kind) {
        await sendDelete(pending.payload.id, scoped);
    } else {
        await sendMove(pending.payload.id, scoped);
    }
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
    if (needsScope(openEvent.value)) {
        askScope("save", "edit", { ...form, occurrenceAt: openEvent.value.occurrenceAt });

        return;
    }

    await sendSave(form);
}

async function sendSave(form) {
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

/**
 * Whether something is open in front of the grid.
 *
 * The shortcuts stay quiet then: Escape is what closes a modal, and a stray `d`
 * while somebody reads an event would move the grid out from under them.
 */
function isBusy() {
    return (
        null !== openEvent.value
        || null !== openReminder.value
        || null !== openCalendar.value
        || sheetOpen.value
    );
}

usePlanningShortcuts({
    isBusy,
    setView,
    go,
    goToToday,
    createEvent: () => create(),
    createReminder: () => createReminder(),
});

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

/**
 * Saves a drag or a resize.
 *
 * Its own route rather than the update endpoint, because the grid holds a
 * serialised event and not an input: `colourSlot` comes down resolved, so echoing
 * it back would turn an event that follows its calendar into one with a colour of
 * its own.
 *
 * Reloads afterwards rather than trusting the local move: the span the server
 * accepted is the one that counts, and an alert that followed the event has moved
 * too.
 */
async function moveEvent(moved) {
    if (needsScope(moved.event)) {
        askScope("move", "edit", {
            id: moved.id,
            startAt: moved.startAt,
            endAt: moved.endAt,
            occurrenceAt: moved.event.occurrenceAt,
        });

        return;
    }

    await sendMove(moved.id, { startAt: moved.startAt, endAt: moved.endAt });
}

async function sendMove(id, body) {
    const data = await request(props.moveEventPathTemplate.replace("__id__", String(id)), body);
    if (!data) return;

    await load();
}

/**
 * Answering an invitation.
 *
 * Reloads and reopens rather than patching the badge in place: the answer changes
 * what every grid draws for that event, and the response carries the whole event
 * back anyway.
 */
async function respond({ event, status }) {
    const data = await request(
        props.respondEventPathTemplate.replace("__id__", String(event.id)),
        { status },
    );
    if (!data) return;

    openEvent.value = data.event;
    await load();
}

async function remove(event) {
    if (needsScope(event)) {
        askScope("delete", "delete", { id: event.id, occurrenceAt: event.occurrenceAt });

        return;
    }

    await sendDelete(event.id, {});
}

async function sendDelete(id, body) {
    const data = await request(props.deleteEventPathTemplate.replace("__id__", String(id)), body);
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
                    <!-- The shortcut is on the title rather than shown as a
                         chip: a calendar's toolbar is already busy, and a reader
                         who wants shortcuts hovers to find them. -->
                    <AppButton
                        variant="ghost"
                        size="sm"
                        :title="t('backend.plannings.shortcuts')"
                        v-on:click="goToToday"
                    >
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
                v-on:move-event="moveEvent"
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
            <CalendarAgenda
                v-else-if="'agenda' === effectiveView"
                :cells="cells"
                :events="visibleEvents"
                :reminders="visibleReminders"
                v-on:open-event="viewEvent"
                v-on:open-reminder="editReminder"
                v-on:toggle-reminder="toggleReminderItem"
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
                v-on:move-event="moveEvent"
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

        <RecurrenceScopeModal
            :show="null !== pendingScope"
            :intent="pendingScope?.intent ?? 'edit'"
            v-on:close="cancelScope"
            v-on:confirm="confirmScope"
        />

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
            :feed-url="feedUrl"
            v-on:publish-feed="publishFeed"
            v-on:revoke-feed="revokeFeed"
            v-on:close="closeCalendar"
            v-on:save="saveCalendar"
            v-on:delete="removeCalendarAndItsEvents"
        />

        <EventModal
            :event="openEvent"
            :editing="editing"
            :calendars="calendars"
            :people="people"
            :current-user-id="currentUserId"
            :errors="errors"
            :saving="saving"
            v-on:close="close"
            v-on:edit="editing = true"
            v-on:respond="respond"
            v-on:save="save"
            v-on:delete="remove"
        />
    </div>
</template>
