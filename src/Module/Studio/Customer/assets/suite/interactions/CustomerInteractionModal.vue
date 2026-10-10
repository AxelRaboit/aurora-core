<script setup>
/**
 * Writing down an exchange: what kind, when, what was said - and, for a new
 * one, when to get back to them next.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { MessageSquareText, Save, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import { siteZone } from "@/shared/utils/format/zonedTime.js";

const props = defineProps({
    /** The exchange being written, or null when closed. */
    form: { type: Object, default: null },
    errors: { type: Object, default: () => ({}) },
    kinds: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["update:form", "close", "submit"]);

const { t } = useI18n();

const kindOptions = computed(() => props.kinds.map((kind) => ({ value: kind.value, label: t(kind.labelKey) })));

function set(field, value) {
    emit("update:form", { ...props.form, [field]: value });
}
</script>

<template>
    <AppModal
        :show="!!form"
        max-width="lg"
        :title="form?.id ? t('suite.studio.customer_interactions.edit') : t('suite.studio.customer_interactions.add')"
        :icon="MessageSquareText"
        :closeable="false"
        :close-on-overlay="false"
        v-on:close="emit('close')"
    >
        <form v-if="form" class="space-y-4" v-on:submit.prevent="emit('submit')">
            <div class="grid gap-4 sm:grid-cols-2">
                <AppSelect
                    :model-value="form.kind"
                    :label="t('suite.studio.customer_interactions.kind')"
                    :options="kindOptions"
                    v-on:update:model-value="set('kind', $event)"
                />
                <!-- The studio's time, whatever the computer's: 14:30 means
                     14:30 where the call was made. -->
                <AppDatePicker
                    :model-value="form.occurredAt"
                    :label="t('suite.studio.customer_interactions.occurred_at')"
                    :error="errors.occurredAt"
                    :time-zone="siteZone()"
                    enable-time
                    required
                    v-on:update:model-value="set('occurredAt', $event)"
                />
            </div>
            <AppTextarea
                :model-value="form.summary"
                :label="t('suite.studio.customer_interactions.summary')"
                :placeholder="t('suite.studio.customer_interactions.summary_placeholder')"
                :error="errors.summary"
                :rows="5"
                required
                v-on:update:model-value="set('summary', $event)"
            />

            <!-- Only on a new exchange: it is what settles the follow-up. -->
            <fieldset v-if="form.setsFollowUp" class="m-0 space-y-3 rounded-lg border border-line p-3">
                <legend class="px-1 text-xs font-semibold uppercase tracking-wider text-secondary">{{ t("suite.studio.customer_interactions.next_follow_up") }}</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppDatePicker
                        :model-value="form.nextFollowUpOn"
                        :label="t('suite.studio.pipeline.follow_up.date')"
                        :hint="t('suite.studio.customer_interactions.next_follow_up_hint')"
                        v-on:update:model-value="set('nextFollowUpOn', $event ?? '')"
                    />
                    <AppInput
                        :model-value="form.followUpNote"
                        :label="t('suite.studio.pipeline.follow_up.note')"
                        :placeholder="t('suite.studio.pipeline.follow_up.note_placeholder')"
                        :error="errors.followUpNote"
                        v-on:update:model-value="set('followUpNote', $event)"
                    />
                </div>
            </fieldset>
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="loading" v-on:click="emit('submit')">
                    <Save class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.save") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
