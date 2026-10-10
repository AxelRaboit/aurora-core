<script setup>
/**
 * A customer's follow-up, on their page: where they stand in the pipeline,
 * when to get back to them, what the deal is worth and where they came from.
 *
 * **The stage moves at once; the rest is saved with the sheet.** Changing a
 * stage is the same gesture as dropping a card on the board - it can open
 * the conversion or ask why a deal was lost - so it does not wait for the
 * sheet's Save. The other fields are part of the sheet and travel with it.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";

const props = defineProps({
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    /** The saved sheet: its status and stage. */
    customer: { type: Object, required: true },
    stages: { type: Array, default: () => [] },
    sources: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    /** A stage change is being sent. */
    moving: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "change-stage"]);

const { t } = useI18n();

const form = computed(() => props.modelValue);

function set(field, value) {
    emit("update:modelValue", { ...props.modelValue, [field]: value });
}

const isProspect = computed(() => "prospect" === props.customer.status);

/**
 * The stage shown: its own, or, for a prospect, the first stage in progress
 * (where the board draws it). A client outside the pipeline has none: it
 * never was a prospect here, and "New" beside a client would be false.
 */
const currentStage = computed(() => {
    const own = props.stages.find((stage) => stage.id === props.customer.pipelineStageId);
    if (own) return own;

    return isProspect.value ? (props.stages.find((stage) => !stage.role) ?? null) : null;
});

/**
 * A client is only ever in the won stage, so it has nothing to choose; a
 * prospect chooses among them all, won being the way to convert.
 */
const stageOptions = computed(() => props.stages.map((stage) => ({ value: stage.id, label: stage.name })));

const sourceOptions = computed(() => [
    { value: "", label: t("suite.studio.customers.sources.none") },
    ...props.sources.map((source) => ({ value: source.value, label: t(source.labelKey) })),
]);

const currencyOptions = computed(() =>
    props.currencies.map((currency) => ({ value: currency.value, label: `${currency.value} (${currency.symbol})` })),
);

const isLost = computed(() => "lost" === currentStage.value?.role);
</script>

<template>
    <article class="aurora-card space-y-4 p-4 sm:p-5" data-customer-pipeline>
        <div class="space-y-0.5">
            <h3 class="m-0 text-[0.9375rem] font-semibold text-primary">{{ t("suite.studio.pipeline.card_title") }}</h3>
            <p class="m-0 text-[0.8125rem] text-secondary">{{ t("suite.studio.pipeline.card_hint") }}</p>
        </div>

        <AppSelect
            v-if="isProspect && stageOptions.length"
            :model-value="currentStage?.id ?? null"
            :label="t('suite.studio.pipeline.stage')"
            :options="stageOptions"
            :disabled="!editable || moving"
            :hint="t('suite.studio.pipeline.stage_hint')"
            data-customer-stage
            v-on:update:model-value="emit('change-stage', Number($event))"
        />
        <p v-else-if="currentStage" class="m-0 text-sm text-secondary">
            {{ t("suite.studio.pipeline.stage") }} : <span class="font-medium text-primary">{{ currentStage.name }}</span>
        </p>

        <AppInput
            v-if="isLost"
            :model-value="form.lostReason"
            :label="t('suite.studio.pipeline.lost_reason')"
            :placeholder="t('suite.studio.pipeline.lost_reason_placeholder')"
            :error="errors.lostReason"
            v-on:update:model-value="set('lostReason', $event)"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <AppDatePicker
                :model-value="form.nextFollowUpOn"
                :label="t('suite.studio.pipeline.follow_up.date')"
                :hint="t('suite.studio.pipeline.follow_up.date_hint')"
                :error="errors.nextFollowUpOn"
                data-follow-up-date
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

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <AppInput
                    :model-value="form.estimatedValue"
                    :label="t('suite.studio.pipeline.estimated_value')"
                    :placeholder="t('suite.studio.pipeline.estimated_value_placeholder')"
                    :hint="t('suite.studio.pipeline.estimated_value_hint')"
                    :error="errors.estimatedValueCents"
                    v-on:update:model-value="set('estimatedValue', $event)"
                />
            </div>
            <AppSelect
                :model-value="form.estimatedValueCurrency"
                :label="t('suite.studio.customers.currency')"
                :options="currencyOptions"
                v-on:update:model-value="set('estimatedValueCurrency', $event)"
            />
        </div>

        <AppSelect
            :model-value="form.source"
            :label="t('suite.studio.customers.source')"
            :options="sourceOptions"
            :hint="customer.sourceReference ? t('suite.studio.customers.source_reference', { reference: customer.sourceReference }) : ''"
            v-on:update:model-value="set('source', $event)"
        />
    </article>
</template>
