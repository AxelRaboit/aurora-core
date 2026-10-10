<script setup>
/**
 * The calendar list and the two things you can make, as a column.
 *
 * The one shape there is now. It was the phone's, beside a `CalendarBar` that
 * drew the same contract on a single line for wide screens - identical props,
 * identical events, two renderings to keep in agreement. The list moved into
 * the side menu's panel, which is a column at every width, so the row above the
 * grid went and this is what both the panel and the menu's own drawer show.
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
    /**
     * Who is reading. Renaming and sharing a calendar are its owner's
     * decisions, not everyone's who holds the right to manage calendars: the
     * server refuses them on anybody else's, and the row stops offering them.
     */
    currentUserId: { type: [Number, null], default: null },
});

/** Whether this row offers its edit and share buttons. */
function ownsCalendar(calendar) {
    return props.canManageCalendars && null !== props.currentUserId && calendar.ownerId === props.currentUserId;
}

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
    <!-- Laid out like the other panels of the menu (sidemenu audit of
         10/10/2026): flat blocks under the same small headings as the menu's
         sections, rows with the menu's padding and figures, and the two
         create buttons in the menu's small size. It
         drew two framed cards inside the column, the only boxes of the
         menu, with their own smaller headings. -->
    <div class="flex flex-col gap-3">
        <!-- Two buttons rather than one with a menu. There are exactly two
             kinds and both are used constantly, so hiding either behind a
             chevron costs a click every time to save a line of height once. -->
        <div v-if="canCreateEvents" class="flex flex-col gap-1.5 px-1">
            <AppButton variant="primary" size="sm" class="w-full" v-on:click="emit('create-event')">
                <CalendarPlus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.plannings.events.new") }}
            </AppButton>
            <AppButton variant="secondary" size="sm" class="w-full" v-on:click="emit('create-reminder')">
                <BellPlus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.plannings.reminders.new") }}
            </AppButton>
        </div>

        <section class="flex flex-col gap-0.5" data-calendar-list>
            <div class="flex items-center gap-2 px-3 py-1">
                <h3 class="m-0 text-xs font-semibold uppercase tracking-wider text-secondary">
                    {{ t("suite.plannings.calendars") }}
                </h3>
                <AppIconButton
                    v-if="canManageCalendars"
                    class="ml-auto -my-1"
                    :title="t('suite.plannings.new_calendar')"
                    v-on:click="emit('create-calendar')"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                </AppIconButton>
            </div>

            <!-- The empty state used to be the end of the road: no calendar
                 meant no way to make one, and the "new event" button below
                 is hidden without one. -->
            <template v-if="!calendars.length">
                <AppNoData :message="t('suite.plannings.empty')" />
                <AppButton
                    v-if="canManageCalendars"
                    variant="primary"
                    size="sm"
                    class="w-full"
                    v-on:click="emit('create-calendar')"
                >
                    <CalendarPlus class="w-4 h-4" :stroke-width="2" />
                    {{ t("suite.plannings.new_calendar") }}
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
                :can-manage="ownsCalendar(calendar)"
                v-on:toggle="emit('toggle-calendar', $event)"
                v-on:edit="emit('edit-calendar', $event)"
                v-on:share="emit('share-calendar', $event)"
            />
        </section>

        <!-- One zone for the screen, not one per calendar: a grid shows several at
             once and a "Tuesday" column cannot be Tuesday in two zones. Kept here
             rather than in the toolbar because it is set once and then forgotten. -->
        <section class="flex flex-col gap-1.5 px-1">
            <h3 class="m-0 px-2 text-xs font-semibold uppercase tracking-wider text-secondary">
                {{ t("suite.plannings.display_zone") }}
            </h3>
            <AppSelect
                :model-value="zone"
                :options="zoneOptions"
                v-on:update:model-value="emit('set-zone', $event)"
            />
        </section>
    </div>
</template>
