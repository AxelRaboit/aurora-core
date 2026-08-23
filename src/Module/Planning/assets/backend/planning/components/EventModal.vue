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
import { Bell, ExternalLink, Globe, MapPin, Pencil, Trash2 } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { DEFAULT_REMINDER_OFFSET, REMINDER_OFFSETS, reminderLabel, toggleReminder } from "../composables/reminderOffsets.js";
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
        // A new event comes with one reminder already on, which is what
        // every calendar people already use does. Nobody sets a reminder
        // they forgot the form offered.
        reminders: [DEFAULT_REMINDER_OFFSET],
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
                reminders: [...(props.event.reminders ?? [])],
            }
            : { ...blank(), ...(props.event ?? {}) };
    },
    { immediate: true },
);

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

const reminderChips = computed(() =>
    REMINDER_OFFSETS.map((minutes) => ({ minutes, label: reminderLabel(minutes, t) })),
);

/** The read view's summary, in the same words the chips use. */
const reminderSummary = computed(() =>
    (props.event?.reminders ?? []).map((minutes) => reminderLabel(minutes, t)).join(" · "),
);

/**
 * The form, with its two wall clocks turned into instants.
 *
 * Converted on the way out and not inside the picker, so the field the reader
 * edits and the value the server stores stay two clearly different things.
 */
function payload() {
    return {
        ...form.value,
        startAt: toInstant(form.value.startAt, zone.value),
        endAt: toInstant(form.value.endAt, zone.value),
    };
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

            <p v-if="reminderSummary" class="flex items-center gap-1.5 text-sm text-secondary">
                <Bell class="w-3.5 h-3.5 shrink-0 text-muted" :stroke-width="2" />
                {{ reminderSummary }}
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

            <!-- Chips and not a select-plus-add-plus-remove: the offsets are a
                 closed list of nine, so one tap is the whole gesture, and the
                 form shows at a glance which are on. A picker would hide the
                 answer behind opening it. -->
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-medium text-primary">{{ t("backend.plannings.reminders.label") }}</span>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="chip in reminderChips"
                        :key="chip.minutes"
                        type="button"
                        class="px-2.5 py-1 text-xs rounded-full border transition-colors cursor-pointer"
                        :class="form.reminders.includes(chip.minutes)
                            ? 'border-accent bg-accent/10 text-primary font-medium'
                            : 'border-line bg-surface-1 text-secondary hover:border-secondary'"
                        :aria-pressed="form.reminders.includes(chip.minutes)"
                        v-on:click="form.reminders = toggleReminder(form.reminders, chip.minutes)"
                    >
                        {{ chip.label }}
                    </button>
                </div>
                <span v-if="!form.reminders.length" class="text-xs text-muted">
                    {{ t("backend.plannings.reminders.none") }}
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
