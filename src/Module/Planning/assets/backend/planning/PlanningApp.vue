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
import CalendarModal from "./components/CalendarModal.vue";
import CalendarShareModal from "./components/CalendarShareModal.vue";
import CalendarDayList from "./components/CalendarDayList.vue";
import CalendarAgenda from "./components/CalendarAgenda.vue";
import CalendarBar from "./components/CalendarBar.vue";
import CalendarSidebar from "./components/CalendarSidebar.vue";
import CalendarMonth from "./components/CalendarMonth.vue";
import CalendarTimeGrid from "./components/CalendarTimeGrid.vue";
import RecurrenceScopeModal from "./components/RecurrenceScopeModal.vue";
import ReminderModal from "./components/ReminderModal.vue";
import EventModal from "./components/EventModal.vue";
import { usePlanningCalendar } from "./composables/usePlanningCalendar.js";
import { usePlanningCalendarForm } from "./composables/usePlanningCalendarForm.js";
import { usePlanningEvents } from "./composables/usePlanningEvents.js";
import { usePlanningReminders } from "./composables/usePlanningReminders.js";
import { defaultTimeOn, draftAt } from "./composables/timeGrid.js";
import { usePlanningShortcuts } from "./composables/usePlanningShortcuts.js";

const props = defineProps({
    calendars: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    people: { type: Array, default: () => [] },
    shareLinks: { type: Array, default: () => [] },
    currentUserId: { type: [Number, null], default: null },
    respondEventPathTemplate: { type: String, required: true },
    eventsPath: { type: String, required: true },
    createCalendarPath: { type: String, required: true },
    createLinkPath: { type: String, required: true },
    revokeLinkPathTemplate: { type: String, required: true },
    sharesCalendarPathTemplate: { type: String, required: true },
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

const {
    calendars,
    visibleEvents,
    zone,
    setZone,
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

const canManageCalendars = computed(() => can("planning.calendars.manage"));

/** An event needs a calendar to live in, so an empty sidebar closes this too. */
const canCreateEvents = computed(() => can("planning.events.create") && calendars.value.length > 0);

/** The sheet has to go before any modal opens, or it opens behind it on a phone. */
function closeSheet() {
    sheetOpen.value = false;
}

const {
    openCalendar,
    openShare,
    openShareFor,
    closeShare,
    linksFor,
    savingLink,
    linkErrors,
    createLink,
    revokeLink,
    saving: savingCalendar,
    errors: calendarErrors,
    createCalendar,
    editCalendar,
    closeCalendar,
    setShares,
    saveCalendar,
    removeCalendarAndItsEvents,
} = usePlanningCalendarForm(props, {
    load,
    upsertCalendar,
    removeCalendar,
    onOpen: closeSheet,
});

const {
    openEvent,
    editing,
    saving,
    errors,
    pendingScope,
    viewEvent,
    create,
    close,
    save,
    remove,
    moveEvent,
    respond,
    cancelScope,
    confirmScope,
} = usePlanningEvents(props, {
    load,
    zone,
    canCreate: canCreateEvents,
    onOpen: closeSheet,
});

const {
    openReminder,
    saving: savingReminder,
    errors: reminderErrors,
    createReminder,
    editReminder,
    closeReminder,
    saveReminder,
    removeReminder: removeReminderItem,
    toggleReminder: toggleReminderItem,
} = usePlanningReminders(props, {
    load,
    canCreate: canCreateEvents,
    onOpen: closeSheet,
});

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
        || null !== openShare.value
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

</script>

<template>
    <div class="relative">
        <AppLoader :active="loading" />

        <div class="min-w-0 space-y-3">
            <!-- Everything the sidebar used to hold, on one line. Hidden below
                 `lg`, where the same content is drawn as a column in the sheet:
                 at 375px a row of pills would scroll further than the grid it
                 filters. -->
            <CalendarBar
                class="hidden lg:flex"
                :calendars="calendars"
                :hidden="hidden"
                :counts-by-calendar="countsByCalendar"
                :can-create-events="canCreateEvents"
                :can-manage-calendars="canManageCalendars"
                :zone="zone"
                :timezones="timezones"
                v-on:set-zone="setZone"
                v-on:create-event="create"
                v-on:create-reminder="createReminder"
                v-on:create-calendar="createCalendar"
                v-on:edit-calendar="editCalendar"
                v-on:share-calendar="openShareFor"
                v-on:toggle-calendar="toggleCalendar"
            />

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
                :zone="zone"
                :timezones="timezones"
                v-on:set-zone="setZone"
                v-on:create-event="create"
                v-on:create-reminder="createReminder"
                v-on:create-calendar="createCalendar"
                v-on:edit-calendar="editCalendar"
                v-on:share-calendar="openShareFor"
                v-on:toggle-calendar="toggleCalendar"
            />
        </AppModal>

        <CalendarShareModal
            :calendar="openShare"
            :links="linksFor(openShare)"
            :errors="linkErrors"
            :saving="savingLink"
            v-on:close="closeShare"
            v-on:create="createLink"
            v-on:revoke="revokeLink"
        />

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
            :people="people"
            :current-user-id="currentUserId"
            v-on:set-shares="setShares"
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
