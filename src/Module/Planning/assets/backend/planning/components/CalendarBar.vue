<script setup>
/**
 * The calendars, the two create buttons and the display zone, on one line above
 * the grid.
 *
 * This used to be a 13rem column beside the grid. On a 1280px screen that column
 * plus its gap took 224 pixels off a seven-day week - 32 pixels per day, which is
 * a whole event title. Moving it up costs one row of height and gives all of that
 * width back.
 *
 * One row and not a stack, deliberately: a calendar wants height as much as width,
 * so a header that grew to three rows would give with one hand and take with the
 * other. Everything here either shrinks or scrolls.
 *
 * The phone keeps the column shape in a sheet - see `CalendarSidebar` - because at
 * 375px a pill row would scroll further than the grid it is filtering.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { BellPlus, CalendarPlus, Plus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import CalendarToggle from "./CalendarToggle.vue";

const props = defineProps({
    calendars: { type: Array, required: true },
    zone: { type: String, default: "" },
    timezones: { type: Array, default: () => [] },
    /** Ids the reader has folded away, as a Set. */
    hidden: { type: Set, required: true },
    /** Items per calendar in the range on screen, keyed by id. */
    countsByCalendar: { type: Object, required: true },
    canCreateEvents: { type: Boolean, default: false },
    canManageCalendars: { type: Boolean, default: false },
});

const emit = defineEmits([
    "set-zone",
    "create-event",
    "create-reminder",
    "create-calendar",
    "edit-calendar",
    "toggle-calendar",
]);

const { t } = useI18n();

const zoneOptions = computed(() =>
    (props.timezones ?? []).map((name) => ({ value: name, label: name.replace(/_/g, " ") })),
);
</script>

<template>
    <div class="flex items-center gap-2">
        <!-- Two buttons rather than one with a menu. There are exactly two kinds
             and both are used constantly, so hiding either behind a chevron costs
             a click every time to save a little width once. -->
        <AppButton
            v-if="canCreateEvents"
            variant="primary"
            size="sm"
            class="shrink-0"
            v-on:click="emit('create-event')"
        >
            <CalendarPlus class="h-4 w-4" :stroke-width="2" />
            {{ t("backend.plannings.events.new") }}
        </AppButton>
        <AppButton
            v-if="canCreateEvents"
            variant="secondary"
            size="sm"
            class="shrink-0"
            v-on:click="emit('create-reminder')"
        >
            <BellPlus class="h-4 w-4" :stroke-width="2" />
            {{ t("backend.plannings.reminders.new") }}
        </AppButton>

        <!-- The one thing allowed to scroll. A reader with fifteen calendars is
             filtering, not reading, so a horizontal scroll here is better than
             wrapping the whole bar onto a second line and shortening the grid for
             everybody else. -->
        <div
            v-if="calendars.length"
            class="flex min-w-0 flex-1 items-center gap-1.5 overflow-x-auto"
        >
            <CalendarToggle
                v-for="calendar in calendars"
                :key="calendar.id"
                pill
                class="shrink-0"
                :calendar="calendar"
                :hidden="hidden.has(calendar.id)"
                :count="countsByCalendar[calendar.id] ?? 0"
                :can-manage="canManageCalendars"
                v-on:toggle="emit('toggle-calendar', $event)"
                v-on:edit="emit('edit-calendar', $event)"
            />
        </div>

        <!-- The empty state used to be the end of the road: no calendar meant no
             way to make one, and the create buttons above are hidden without one.
             Spelled out here rather than shown as a bare `+`, because somebody
             with no calendars has nothing on screen to explain the icon. -->
        <AppButton
            v-else-if="canManageCalendars"
            variant="primary"
            size="sm"
            class="shrink-0"
            v-on:click="emit('create-calendar')"
        >
            <CalendarPlus class="h-4 w-4" :stroke-width="2" />
            {{ t("backend.plannings.new_calendar") }}
        </AppButton>
        <span v-else class="flex-1" />

        <AppIconButton
            v-if="canManageCalendars && calendars.length"
            class="shrink-0"
            :title="t('backend.plannings.new_calendar')"
            v-on:click="emit('create-calendar')"
        >
            <Plus class="h-4 w-4" :stroke-width="2" />
        </AppIconButton>

        <!-- One zone for the screen, not one per calendar: a grid shows several at
             once and a "Tuesday" column cannot be Tuesday in two zones. Unlabelled
             here because it is set once and then forgotten, and a label on one line
             beside eleven other controls is a word nobody reads twice. Its title
             carries the name for anyone who needs it. -->
        <div class="w-40 shrink-0">
            <AppSelect
                :model-value="zone"
                :options="zoneOptions"
                :title="t('backend.plannings.display_zone')"
                v-on:update:model-value="emit('set-zone', $event)"
            />
        </div>
    </div>
</template>
