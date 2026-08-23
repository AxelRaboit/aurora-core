<script setup>
/**
 * One event, read or edited.
 *
 * Both in one component because the read view and the form answer the same
 * question about the same record, and the rule that separates them - an event a
 * module owns is not ours to change - has to be applied once. Two components
 * would each have to know it.
 *
 * A modal rather than the popover anchored to the clicked cell that the mockup
 * drew. The month stays visible behind it, which was the point of not opening a
 * page; anchoring to a cell inside a scrolling grid is its own piece of work and
 * not what makes the calendar usable.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Bell, ExternalLink, Globe, MapPin, Pencil, Plus, Trash2, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { CUSTOM, alertLabel, alertOptions, blankRow, fromRow, toRow } from "../composables/alertOffsets.js";
import { toInstant, toPickerValue, zoneDiffersFromViewer } from "../composables/eventTime.js";

const props = defineProps({
    /** The event being looked at, or null when the modal is closed. */
    event: { type: Object, default: null },
    /** True when the form is open rather than the read view. */
    editing: { type: Boolean, default: false },
    calendars: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "edit", "save", "delete"]);

const { t, d } = useI18n();

const form = ref(blank());

function blank() {
    return {
        planningId: props.calendars[0]?.id ?? null,
        title: "",
        description: "",
        location: "",
        startAt: "",
        endAt: "",
        allDay: false,
        status: "confirmed",
        // A new event comes with one alert already on, which is what every
        // calendar people already use does. Nobody sets an alert they forgot the
        // form offered.
        alerts: [blankRow()],
    };
}

// Refilled whenever a different event opens, so the form never shows the
// previous one's values for an instant.
watch(
    () => [props.event, props.editing],
    () => {
        if (!props.editing) return;

        const eventZone = props.event?.id
            ? (props.calendars.find((calendar) => calendar.id === props.event.planningId)?.timezone ?? "")
            : "";

        form.value = props.event?.id
            ? {
                planningId: props.event.planningId,
                title: props.event.title,
                description: props.event.description ?? "",
                location: props.event.location ?? "",
                startAt: toPickerValue(props.event.startAt, eventZone),
                endAt: toPickerValue(props.event.endAt, eventZone),
                allDay: props.event.allDay,
                status: props.event.status,
                alerts: (props.event.alerts ?? []).map(toRow),
            }
            : draft();
    },
    { immediate: true },
);

/**
 * A blank form, plus whatever a click on the grid handed in.
 *
 * The draft carries instants, because the grid is drawn in the reader's own zone
 * and a click there means the moment they were pointing at. They become wall
 * clocks here, on the calendar's clock - which is a different number when the
 * calendar lives elsewhere, and the right one.
 */
function draft() {
    const base = { ...blank(), ...(props.event ?? {}) };
    const draftZone =
        props.calendars.find((calendar) => calendar.id === base.planningId)?.timezone ?? "";

    return {
        ...base,
        startAt: base.startAt ? toPickerValue(base.startAt, draftZone) : "",
        endAt: base.endAt ? toPickerValue(base.endAt, draftZone) : "",
    };
}

/**
 * The zone the form's two times mean, taken from the calendar they land in.
 *
 * The calendar carries a timezone, so "10:00" on it means 10:00 there - not
 * 10:00 wherever the person filling the form happens to be sitting. Before this
 * the picker was read in the browser's zone, which is the same answer only for
 * as long as everybody shares one.
 *
 * Read off the form's own calendar picker rather than the event's stored one, so
 * moving an event to a calendar in another zone reinterprets its times the moment
 * the reader chooses - which is the only point at which they can see it happen.
 */
const zone = computed(
    () => props.calendars.find((calendar) => calendar.id === form.value.planningId)?.timezone ?? "",
);

/**
 * Whether the form has to name the zone.
 *
 * Saying "Europe/Paris" under a field a reader in Paris is filling in is noise.
 * Leaving it out when they are somewhere else lets them type the wrong time and
 * see no reason why.
 */
const showZone = computed(() => zoneDiffersFromViewer(zone.value));

const calendarOptions = computed(() =>
    props.calendars.map((calendar) => ({ value: calendar.id, label: calendar.name })),
);

const statusOptions = computed(() =>
    ["tentative", "confirmed", "cancelled"].map((value) => ({
        value,
        label: t(`backend.plannings.events.status.${value}`),
    })),
);

const alertSelectOptions = computed(() => alertOptions(t));

/**
 * The read view's summary.
 *
 * A pinned alert is named by its moment and a relative one by its offset,
 * because that is what each of them is - and showing "30 minutes before" for a
 * moment somebody chose would be arithmetic the reader has to undo.
 */
const alertSummary = computed(() =>
    (props.event?.alerts ?? [])
        .map((alert) =>
            null === alert.minutes
                ? d(new Date(alert.at), { dateStyle: "medium", timeStyle: "short" })
                : alertLabel(alert.minutes, t),
        )
        .join(" · "),
);

function addAlert() {
    form.value.alerts = [...form.value.alerts, blankRow()];
}

function removeAlertRow(index) {
    form.value.alerts = form.value.alerts.filter((_, at) => at !== index);
}

const when = computed(() => {
    if (!props.event) return "";

    const start = new Date(props.event.startAt);
    // The calendar's zone, so the read view agrees with the form that wrote it.
    // A reader who typed 10:00 and is shown 04:00 has no way to tell whether the
    // save was wrong or the display is.
    const eventZone =
        props.calendars.find((calendar) => calendar.id === props.event.planningId)?.timezone ?? "";
    const inZone = eventZone ? { timeZone: eventZone } : {};

    if (props.event.allDay) {
        return `${d(start, { dateStyle: "long", ...inZone })} · ${t("backend.plannings.events.all_day")}`;
    }

    const clock = d(start, { hour: "2-digit", minute: "2-digit", ...inZone });
    const suffix = zoneDiffersFromViewer(eventZone) ? ` (${eventZone})` : "";

    return `${d(start, { dateStyle: "long", ...inZone })} · ${clock}${suffix}`;
});
</script>

<template>
    <AppModal
        :show="null !== event"
        max-width="md"
        mobile-fullscreen
        :close-on-overlay="!editing"
        :title="editing ? (event?.id ? t('shared.common.edit') : t('backend.plannings.events.new')) : ''"
        v-on:close="emit('close')"
    >
        <!-- Read view -->
        <div v-if="event && !editing" class="space-y-3">
            <div class="flex items-start gap-2.5">
                <span
                    class="w-[3px] self-stretch rounded-sm shrink-0"
                    :style="{ backgroundColor: `var(--chart-cat-${event.colourSlot})` }"
                />
                <div class="min-w-0">
                    <p class="text-base font-semibold text-primary leading-tight">{{ event.title }}</p>
                    <p class="mt-0.5 text-sm text-secondary">{{ when }}</p>
                </div>
                <AppBadge :color="event.statusColor" class="ml-auto shrink-0">{{ event.statusLabel }}</AppBadge>
            </div>

            <p v-if="event.location" class="flex items-center gap-1.5 text-sm text-secondary">
                <MapPin class="w-3.5 h-3.5 shrink-0 text-muted" :stroke-width="2" />
                {{ event.location }}
            </p>

            <p v-if="event.description" class="text-sm text-secondary whitespace-pre-line">{{ event.description }}</p>

            <p v-if="alertSummary" class="flex items-center gap-1.5 text-sm text-secondary">
                <Bell class="w-3.5 h-3.5 shrink-0 text-muted" :stroke-width="2" />
                {{ alertSummary }}
            </p>

            <!-- An event a module pushed says where it came from and offers
                 nothing else: it reflects a date that lives elsewhere, and the
                 only useful gesture is to go to the source. -->
            <template v-if="event.readOnly">
                <p class="text-xs text-muted">
                    {{ t("backend.plannings.events.from_module") }}{{ event.sourceLabel ? ` · ${event.sourceLabel}` : "" }}
                </p>
                <!-- The only useful gesture on an event a module owns. Editing it
                     here would be undone by the source's next announcement, which
                     is why the manager refuses it. -->
                <a
                    v-if="event.sourceUrl"
                    :href="event.sourceUrl"
                    class="inline-flex items-center gap-1.5 text-sm text-accent-600 hover:underline"
                >
                    <ExternalLink class="w-3.5 h-3.5 shrink-0" :stroke-width="2" />
                    {{ t("backend.plannings.events.open_source") }}
                </a>
            </template>
        </div>

        <!-- Form -->
        <div v-else-if="event" class="space-y-3">
            <AppInput
                v-model="form.title"
                :label="t('backend.plannings.events.title')"
                :placeholder="t('shared.placeholders.title')"
                :error="errors.title"
            />
            <AppSelect
                v-model="form.planningId"
                :label="t('backend.plannings.calendars')"
                :options="calendarOptions"
                :error="errors.planningId"
            />
            <AppToggle v-model="form.allDay" :label="t('backend.plannings.events.all_day')" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <!-- The shared picker, not a bare `datetime-local`: it is what
                     every other date field in the backend uses, so it carries
                     the theme, the locale and the same keyboard behaviour. Its
                     value contract is the wall clock the reader sees; the
                     instant is built on save. -->
                <AppDatePicker
                    v-model="form.startAt"
                    enable-time
                    :label="t('backend.plannings.events.starts')"
                    :placeholder="t('backend.plannings.events.starts_placeholder')"
                    :error="errors.startAt"
                />
                <AppDatePicker
                    v-model="form.endAt"
                    enable-time
                    :label="t('backend.plannings.events.ends')"
                    :placeholder="t('backend.plannings.events.ends_placeholder')"
                    :error="errors.endAt"
                />
            </div>

            <p v-if="showZone" class="flex items-center gap-1.5 -mt-1.5 text-xs text-muted">
                <Globe class="w-3.5 h-3.5 shrink-0" :stroke-width="2" />
                {{ t("backend.plannings.events.in_zone", { zone }) }}
            </p>
            <AppInput
                v-model="form.location"
                :label="t('backend.plannings.events.location')"
                :placeholder="t('backend.plannings.events.location_placeholder')"
            />
            <AppSelect v-model="form.status" :label="t('shared.common.status')" :options="statusOptions" />
            <AppTextarea
                v-model="form.description"
                :label="t('backend.plannings.description')"
                :placeholder="t('shared.placeholders.description')"
                :rows="3"
            />

            <!-- One row per alert, each a select with the offsets the menu
                 offers plus "custom" - which is the shape Google and Apple both
                 use. A row of chips said the same thing in fewer clicks but in a
                 control that exists nowhere else in this application, and it
                 could not express a moment at all. -->
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-medium text-primary">{{ t("backend.plannings.alerts.label") }}</span>

                <div v-for="(row, index) in form.alerts" :key="index" class="flex items-start gap-2">
                    <!-- The select and its picker share one column, so they line
                         up on both edges. Left to the row, the picker would take
                         the width the remove button occupies and sit wider than
                         the select above it. -->
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <AppSelect v-model="row.choice" :options="alertSelectOptions" />
                        <AppDatePicker
                            v-if="CUSTOM === row.choice"
                            v-model="row.at"
                            enable-time
                            :placeholder="t('backend.plannings.alerts.at_placeholder')"
                        />
                    </div>
                    <button
                        type="button"
                        class="mt-1.5 shrink-0 p-1.5 text-muted hover:text-primary rounded-lg hover:bg-surface-2 transition-colors cursor-pointer"
                        :title="t('shared.common.delete')"
                        v-on:click="removeAlertRow(index)"
                    >
                        <X class="w-4 h-4" :stroke-width="2" />
                    </button>
                </div>

                <button
                    type="button"
                    class="flex items-center gap-1.5 self-start text-xs text-accent-600 hover:underline cursor-pointer"
                    v-on:click="addAlert"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.plannings.alerts.add") }}
                </button>

                <span v-if="!form.alerts.length" class="text-xs text-muted">
                    {{ t("backend.plannings.alerts.none") }}
                </span>
            </div>
        </div>

        <template #footer>
            <AppModalFooter>
                <template v-if="event && !editing">
                    <AppButton v-if="!event.readOnly" variant="ghost" size="md" v-on:click="emit('delete', event)">
                        <Trash2 class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.delete") }}
                    </AppButton>
                    <AppButton v-if="!event.readOnly" variant="primary" size="md" v-on:click="emit('edit', event)">
                        <Pencil class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.edit") }}
                    </AppButton>
                    <AppButton v-else variant="primary" size="md" v-on:click="emit('close')">
                        {{ t("shared.common.close") }}
                    </AppButton>
                </template>
                <template v-else>
                    <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('save', payload())">
                        {{ t("shared.common.save") }}
                    </AppButton>
                </template>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
