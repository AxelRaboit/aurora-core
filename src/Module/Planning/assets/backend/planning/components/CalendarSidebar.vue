<script setup>
/**
 * The calendar list and the two things you can make, as a column.
 *
 * The phone's shape only, since the wide screen moved all of this into
 * `CalendarBar` above the grid. It stays a separate component rather than a
 * breakpoint on that one because the two are not the same layout with different
 * padding: this one stacks full-width blocks in a sheet, that one fits on a single
 * line. What they genuinely share - drawing one calendar - is `CalendarToggle`.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarPlus, BellPlus, Plus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import CalendarToggle from "./CalendarToggle.vue";

const props = defineProps({
    calendars: { type: Array, required: true },
    /** The zone the screen draws in, and the names it may take. */
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
    "share-calendar",
    "toggle-calendar",
]);

const { t } = useI18n();

const zoneOptions = computed(() =>
    (props.timezones ?? []).map((name) => ({ value: name, label: name.replace(/_/g, " ") })),
);
</script>

<template>
    <div class="space-y-4">
        <AppButton
            v-if="canCreateEvents"
            variant="primary"
            size="md"
            class="w-full"
            v-on:click="emit('create-event')"
        >
            <CalendarPlus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.plannings.events.new") }}
        </AppButton>

        <!-- Two buttons rather than one with a menu. There are exactly two
             kinds and both are used constantly, so hiding either behind a
             chevron costs a click every time to save a line of height once. -->
        <AppButton
            v-if="canCreateEvents"
            variant="secondary"
            size="md"
            class="w-full"
            v-on:click="emit('create-reminder')"
        >
            <BellPlus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.plannings.reminders.new") }}
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
                    v-on:click="emit('create-calendar')"
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
                    v-on:click="emit('create-calendar')"
                >
                    <CalendarPlus class="w-4 h-4" :stroke-width="2" />
                    {{ t("backend.plannings.new_calendar") }}
                </AppButton>
            </template>

            <!-- A calendar folded away is a display decision, so it toggles
                 without a round trip and without touching the URL: which of
                 your own calendars you have hidden is not something you send
                 anyone. -->
            <CalendarToggle
                v-for="calendar in calendars"
                :key="calendar.id"
                :calendar="calendar"
                :hidden="hidden.has(calendar.id)"
                :count="countsByCalendar[calendar.id] ?? 0"
                :can-manage="canManageCalendars"
                v-on:toggle="emit('toggle-calendar', $event)"
                v-on:edit="emit('edit-calendar', $event)"
                v-on:share="emit('share-calendar', $event)"
            />
        </div>

        <!-- One zone for the screen, not one per calendar: a grid shows several at
             once and a "Tuesday" column cannot be Tuesday in two zones. Kept here
             rather than in the toolbar because it is set once and then forgotten. -->
        <div class="bg-surface border border-line rounded-xl p-4 space-y-2">
            <p class="text-2xs font-semibold uppercase tracking-wider text-muted">
                {{ t("backend.plannings.display_zone") }}
            </p>
            <AppSelect
                :model-value="zone"
                :options="zoneOptions"
                v-on:update:model-value="emit('set-zone', $event)"
            />
        </div>
    </div>
</template>
