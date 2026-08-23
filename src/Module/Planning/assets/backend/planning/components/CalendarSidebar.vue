<script setup>
/**
 * The calendar list and the three things you can make.
 *
 * Its own component because it is drawn in two places: a column beside the grid
 * on a wide screen, and a sheet on a phone, where a 13rem sidebar would leave
 * 167 pixels for a seven-day grid. Both Google and Apple put it behind a button
 * there. Duplicating the markup would mean fixing every future change twice.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarPlus, BellPlus, Pencil, Plus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";

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
                    v-on:click="emit('toggle-calendar', calendar.id)"
                >
                    <span
                        class="w-3 h-3 rounded shrink-0"
                        :style="hidden.has(calendar.id)
                            ? { border: '1.5px solid var(--th-muted)' }
                            : { backgroundColor: `var(--chart-cat-${calendar.colourSlot})` }"
                    />
                    <span class="text-secondary truncate">{{ calendar.name }}</span>
                </button>

                <!-- Events in the range on screen, not a lifetime total.
                     Titled, because a bare number next to a calendar reads
                     as "everything in it" and this one moves as you page.

                     Absent rather than "0" when there is nothing: a zero in
                     figures reads as a fact about the calendar, and this one
                     is a fact about the month. No number says "nothing here
                     right now", which is what it means. -->
                <span
                    v-if="countsByCalendar[calendar.id]"
                    class="text-2xs text-muted tabular-nums shrink-0 group-hover:hidden"
                    :title="t('backend.plannings.events_in_range')"
                >
                    {{ countsByCalendar[calendar.id] }}
                </span>
                <!-- Takes the count's place on hover rather than sitting
                     beside it, so the row does not change width and the
                     names stay lined up. -->
                <button
                    v-if="canManageCalendars"
                    type="button"
                    class="hidden shrink-0 cursor-pointer text-muted hover:text-primary group-hover:block"
                    :title="t('backend.plannings.edit_calendar')"
                    v-on:click="emit('edit-calendar', calendar)"
                >
                    <Pencil class="w-3.5 h-3.5" :stroke-width="2" />
                </button>
            </div>
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
