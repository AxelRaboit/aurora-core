<script setup>
/**
 * One calendar, created or edited.
 *
 * The screen had none of this: the three routes existed and were tested, and
 * nothing ever called them - so a fresh installation showed an empty sidebar,
 * with the "new event" button hidden because an event needs a calendar. The page
 * was unusable until a fixture made the first one.
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
import { COLOUR_SLOTS, nextFreeColourSlot } from "../composables/calendarColours.js";

const props = defineProps({
    /** The calendar being edited, `{}` for a new one, null when closed. */
    calendar: { type: Object, default: null },
    /** Every calendar on screen, so a new one can take a colour nobody uses. */
    calendars: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "save", "delete"]);

const { t } = useI18n();

const form = ref(blank());

function blank() {
    return {
        name: "",
        description: "",
        colourSlot: nextFreeColourSlot(props.calendars),
        timezone: defaultTimezone(),
        visibility: "private",
    };
}

/**
 * The reader's own zone, which is the only sensible default.
 *
 * It used to be `timezones[0]`, and that list arrives alphabetically from
 * `DateTimeZone::listIdentifiers()` - so every new calendar was born in
 * Africa/Abidjan, and every all-day event on it was cut on Abidjan's midnight.
 *
 * Falls back to the entity's own default rather than to nothing, and checks the
 * zone is one the picker offers: a browser reporting a name PHP does not know
 * would otherwise preselect an option that is not in the list, which shows as an
 * empty select.
 */
function defaultTimezone() {
    const mine = Intl.DateTimeFormat().resolvedOptions().timeZone;

    if (mine && props.timezones.includes(mine)) {
        return mine;
    }

    return props.timezones.includes("Europe/Paris") ? "Europe/Paris" : (props.timezones[0] ?? "Europe/Paris");
}

const isNew = computed(() => !props.calendar?.id);

watch(
    () => props.calendar,
    () => {
        if (null === props.calendar) return;

        form.value = props.calendar.id
            ? {
                name: props.calendar.name,
                description: props.calendar.description ?? "",
                colourSlot: props.calendar.colourSlot,
                timezone: props.calendar.timezone,
                visibility: props.calendar.visibility,
            }
            : blank();
    },
    { immediate: true },
);

const timezoneOptions = computed(() =>
    props.timezones.map((zone) => ({ value: zone, label: zone.replace(/_/g, " ") })),
);

const visibilityOptions = computed(() =>
    ["private", "shared"].map((value) => ({
        value,
        label: t(`backend.plannings.visibility.${value}`),
    })),
);
</script>

<template>
    <AppModal
        :show="null !== calendar"
        max-width="md"
        mobile-fullscreen
        :title="isNew ? t('backend.plannings.new_calendar') : t('backend.plannings.edit_calendar')"
        v-on:close="emit('close')"
    >
        <div v-if="calendar" class="space-y-3">
            <AppInput
                v-model="form.name"
                :label="t('backend.plannings.name')"
                :placeholder="t('backend.plannings.name_placeholder')"
                :error="errors.name"
            />

            <!-- Swatches and not a select: the thing being chosen is the colour
                 itself, so showing it beats naming it - and these are the same
                 eight tokens the grid draws with, so what the reader picks here
                 is literally what they will see. -->
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-medium text-primary">{{ t("backend.plannings.colour") }}</span>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="slot in COLOUR_SLOTS"
                        :key="slot"
                        type="button"
                        class="w-7 h-7 rounded-lg border-2 transition-transform cursor-pointer hover:scale-110"
                        :class="form.colourSlot === slot ? 'border-primary scale-110' : 'border-transparent'"
                        :style="{ backgroundColor: `var(--chart-cat-${slot})` }"
                        :aria-label="t('backend.plannings.colour_slot', { slot })"
                        :aria-pressed="form.colourSlot === slot"
                        v-on:click="form.colourSlot = slot"
                    />
                </div>
                <p v-if="errors.colourSlot" class="text-xs text-red-500">{{ errors.colourSlot }}</p>
            </div>

            <AppSelect
                v-model="form.visibility"
                :label="t('backend.plannings.visibility.label')"
                :options="visibilityOptions"
                :hint="t('backend.plannings.visibility_hint')"
            />

            <AppSelect
                v-model="form.timezone"
                :label="t('backend.plannings.timezone')"
                :options="timezoneOptions"
                :hint="t('backend.plannings.timezone_hint')"
                :error="errors.timezone"
            />

            <AppTextarea
                v-model="form.description"
                :label="t('backend.plannings.description')"
                :placeholder="t('shared.placeholders.description')"
                :rows="2"
            />
        </div>

        <template #footer>
            <AppModalFooter>
                <!-- Deleting a calendar takes its events with it, so the control
                     sits here rather than on a hover icon in the sidebar: the
                     reader is already looking at what they are about to lose. -->
                <AppButton
                    v-if="!isNew"
                    variant="ghost"
                    size="md"
                    v-on:click="emit('delete', calendar)"
                >
                    <Trash2 class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.delete") }}
                </AppButton>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('save', form)">
                    {{ t("shared.common.save") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
