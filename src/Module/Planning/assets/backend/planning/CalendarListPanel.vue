<script setup>
/**
 * The calendars, in the side menu.
 *
 * The rows are `CalendarSidebar`, the component the mobile sheet already used,
 * and every one of its events is handed straight to the page. Rebuilding it
 * here would have been the mistake the notes tree made: a hand-written list
 * only has what somebody remembered to give it, and this one carries a
 * visibility toggle, a count, two create buttons, sharing and the timezone.
 *
 * The page always exists while this panel is on screen - Planning has one
 * destination - so the state arrives by announcement rather than by a fetch of
 * our own. There is no endpoint that would return all of it anyway: the counts
 * depend on the range the grid is showing.
 */
import { onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import AppModulePanel from "@/shared/nav/AppModulePanel.vue";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";
import CalendarSidebar from "./components/CalendarSidebar.vue";

const { t } = useI18n();

/** Everything `CalendarSidebar` needs, as the page last announced it. */
const state = ref(null);

function forward(name, ...args) {
    askPage(`planning:${name}`, { args });
}

const stopListening = [];

onMounted(() => {
    stopListening.push(
        onPageNotice("planning:changed", (detail) => {
            state.value = detail ?? null;
        }),
    );

    // The panel can mount after the grid did, in which case its announcement
    // is already behind us. Asking for one costs nothing and settles it.
    askPage("planning:announce");
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
});
</script>

<template>
    <AppModulePanel
        :title="t('backend.plannings.calendars')"
        :loading="null === state"
    >
        <CalendarSidebar
            v-if="state"
            :calendars="state.calendars"
            :hidden="new Set(state.hidden)"
            :counts-by-calendar="state.countsByCalendar"
            :can-create-events="state.canCreateEvents"
            :can-manage-calendars="state.canManageCalendars"
            :zone="state.zone"
            :timezones="state.timezones"
            v-on:set-zone="(...a) => forward('set-zone', ...a)"
            v-on:create-event="(...a) => forward('create-event', ...a)"
            v-on:create-reminder="(...a) => forward('create-reminder', ...a)"
            v-on:create-calendar="(...a) => forward('create-calendar', ...a)"
            v-on:edit-calendar="(...a) => forward('edit-calendar', ...a)"
            v-on:share-calendar="(...a) => forward('share-calendar', ...a)"
            v-on:toggle-calendar="(...a) => forward('toggle-calendar', ...a)"
        />
    </AppModulePanel>
</template>
