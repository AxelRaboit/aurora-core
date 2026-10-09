<script setup>
/**
 * « Remind me of this note » (09/10/2026): a notification in the suite at
 * the chosen time. The usual moments in one click, any other in the picker,
 * read and written in the site's time zone.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { BellRing } from "lucide-vue-next";
import AppModal from "@shared/components/overlay/AppModal.vue";
import AppButton from "@shared/components/action/AppButton.vue";
import AppDatePicker from "@shared/components/form/picker/AppDatePicker.vue";
import { siteZone } from "@/shared/utils/format/zonedTime.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** The reminder waiting, ISO 8601 with its offset, or null. */
    current: { type: String, default: null },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "save"]);

const { t } = useI18n();
const timeZone = siteZone() ?? "";
const chosen = ref("");

watch(
    () => props.show,
    (open) => {
        if (open) chosen.value = props.current ?? "";
    },
);

/** A moment as the picker returns it: a wall time with the offset of its zone. */
function atHour(date, hour) {
    const moment = new Date(date);
    moment.setHours(hour, 0, 0, 0);

    return moment.toISOString();
}

const quickChoices = computed(() => {
    const now = new Date();
    const inAnHour = new Date(now.getTime() + 60 * 60 * 1000);
    inAnHour.setSeconds(0, 0);
    const tomorrow = new Date(now);
    tomorrow.setDate(now.getDate() + 1);
    const nextMonday = new Date(now);
    nextMonday.setDate(now.getDate() + (((8 - now.getDay()) % 7) || 7));

    return [
        { key: "in_an_hour", at: inAnHour.toISOString() },
        { key: "tomorrow", at: atHour(tomorrow, 9) },
        { key: "next_week", at: atHour(nextMonday, 9) },
    ];
});

const isPast = computed(() => "" !== chosen.value && Date.parse(chosen.value) <= Date.now());
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        :title="t('notes.markdown.reminder.title')"
        :icon="BellRing"
        v-on:close="emit('close')"
    >
        <div class="flex flex-col gap-4">
            <p class="text-sm text-muted">{{ t('notes.markdown.reminder.intro') }}</p>
            <div class="flex flex-wrap gap-2">
                <AppButton
                    v-for="choice in quickChoices"
                    :key="choice.key"
                    variant="secondary"
                    size="sm"
                    :data-note-reminder-quick="choice.key"
                    :disabled="saving"
                    v-on:click="emit('save', choice.at)"
                >
                    {{ t(`notes.markdown.reminder.quick.${choice.key}`) }}
                </AppButton>
            </div>
            <AppDatePicker
                v-model="chosen"
                enable-time
                :time-zone="timeZone"
                :label="t('notes.markdown.reminder.at')"
                :error="isPast ? t('notes.markdown.reminder.past') : ''"
            />
        </div>
        <template #footer>
            <div class="flex w-full flex-wrap items-center gap-2">
                <AppButton
                    v-if="current"
                    variant="ghost"
                    data-note-reminder-remove
                    :disabled="saving"
                    v-on:click="emit('save', null)"
                >
                    {{ t('notes.markdown.reminder.remove') }}
                </AppButton>
                <AppButton
                    class="ml-auto"
                    data-note-reminder-save
                    :disabled="saving || '' === chosen || isPast"
                    v-on:click="emit('save', chosen)"
                >
                    {{ t('notes.markdown.reminder.save') }}
                </AppButton>
            </div>
        </template>
    </AppModal>
</template>
