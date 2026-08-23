<script setup>
/**
 * One reminder, read or edited.
 *
 * A separate modal from the event's, not a mode of it. They look similar and are
 * not: an event has two ends, a place and a status; a reminder has one date, notes
 * and a checkbox. One component with both would be two forms behind a flag, and
 * the flag would be read in a dozen places.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Trash2 } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import { toInstant, toPickerValue, zoneDiffersFromViewer } from "../composables/eventTime.js";

const props = defineProps({
    /** The reminder being edited, `{}` for a new one, null when closed. */
    reminder: { type: Object, default: null },
    calendars: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "save", "delete"]);

const { t } = useI18n();

const form = ref(blank());

function blank() {
    return {
        planningId: props.calendars[0]?.id ?? null,
        title: "",
        notes: "",
        dueAt: "",
        allDay: false,
        completed: false,
    };
}

const isNew = computed(() => !props.reminder?.id);

/**
 * The zone the due date means, taken from the calendar it lands in.
 *
 * Same rule as events, and it has to be the same rule: a calendar carries a
 * timezone, so a date on it means that zone's clock rather than the clock of
 * whoever is filling the form.
 */
const zone = computed(
    () => props.calendars.find((calendar) => calendar.id === form.value.planningId)?.timezone ?? "",
);

const showZone = computed(() => zoneDiffersFromViewer(zone.value));

watch(
    () => props.reminder,
    () => {
        if (null === props.reminder) return;

        const base = { ...blank(), ...props.reminder };
        const baseZone =
            props.calendars.find((calendar) => calendar.id === base.planningId)?.timezone ?? "";

        form.value = { ...base, dueAt: base.dueAt ? toPickerValue(base.dueAt, baseZone) : "" };
    },
    { immediate: true },
);

const calendarOptions = computed(() =>
    props.calendars.map((calendar) => ({ value: calendar.id, label: calendar.name })),
);

function payload() {
    return { ...form.value, dueAt: toInstant(form.value.dueAt, zone.value) };
}
</script>

<template>
    <AppModal
        :show="null !== reminder"
        max-width="md"
        :close-on-overlay="false"
        :title="isNew ? t('backend.plannings.reminders.new') : t('backend.plannings.reminders.edit')"
        v-on:close="emit('close')"
    >
        <div v-if="reminder" class="space-y-3">
            <AppInput
                v-model="form.title"
                :label="t('backend.plannings.reminders.title')"
                :placeholder="t('backend.plannings.reminders.title_placeholder')"
                :error="errors.title"
            />
            <AppSelect
                v-model="form.planningId"
                :label="t('backend.plannings.calendars')"
                :options="calendarOptions"
                :error="errors.planningId"
            />
            <AppToggle v-model="form.allDay" :label="t('backend.plannings.reminders.all_day')" />
            <AppDatePicker
                v-model="form.dueAt"
                enable-time
                :label="t('backend.plannings.reminders.due')"
                :placeholder="t('backend.plannings.reminders.due_placeholder')"
                :error="errors.dueAt"
            />
            <p v-if="showZone" class="-mt-1.5 text-xs text-muted">
                {{ t("backend.plannings.events.in_zone", { zone }) }}
            </p>
            <AppTextarea
                v-model="form.notes"
                :label="t('backend.plannings.reminders.notes')"
                :placeholder="t('backend.plannings.reminders.notes_placeholder')"
                :rows="3"
            />
            <!-- On the form as well as on the grid, because editing something and
                 finishing it are the same gesture as often as not. -->
            <AppToggle v-model="form.completed" :label="t('backend.plannings.reminders.completed')" />
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton v-if="!isNew" variant="ghost" size="md" v-on:click="emit('delete', reminder)">
                    <Trash2 class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.delete") }}
                </AppButton>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('save', payload())">
                    {{ t("shared.common.save") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
