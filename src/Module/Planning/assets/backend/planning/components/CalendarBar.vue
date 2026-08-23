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
 * other. Nothing here scrolls or truncates either - the first version laid the
 * calendars out as pills across the bar, and with seven of them the row scrolled
 * sideways and clipped names mid-word. They live behind `CalendarPicker` now.
 *
 * The phone keeps the column shape in a sheet - see `CalendarSidebar` - because at
 * 375px a pill row would scroll further than the grid it is filtering.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { BellPlus, CalendarPlus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import CalendarPicker from "./CalendarPicker.vue";

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

        <!-- The empty state used to be the end of the road: no calendar meant no
             way to make one, and the create buttons above are hidden without one.
             Spelled out rather than left to the picker, because somebody with no
             calendars should not have to open a filter to find the way out. -->
        <AppButton
            v-if="!calendars.length && canManageCalendars"
            variant="primary"
            size="sm"
            class="shrink-0"
            v-on:click="emit('create-calendar')"
        >
            <CalendarPlus class="h-4 w-4" :stroke-width="2" />
            {{ t("backend.plannings.new_calendar") }}
        </AppButton>

        <span class="flex-1" />

        <CalendarPicker
            :calendars="calendars"
            :hidden="hidden"
            :counts-by-calendar="countsByCalendar"
            :can-manage-calendars="canManageCalendars"
            v-on:create-calendar="emit('create-calendar')"
            v-on:edit-calendar="emit('edit-calendar', $event)"
            v-on:toggle-calendar="emit('toggle-calendar', $event)"
        />

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
