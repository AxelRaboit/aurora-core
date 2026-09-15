<script setup>
/**
 * The form of one piece of content.
 *
 * Four fields and a notice. The notice is the one thing that is not obvious:
 * the hour typed here is read in the space's timezone, not the reader's, so a
 * person on holiday abroad does not move a client's Tuesday morning by opening
 * the page.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";

const props = defineProps({
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    columnOptions: { type: Array, default: () => [] },
    timezone: { type: String, default: "Europe/Paris" },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const form = computed(() => props.modelValue);

function set(field, value) {
    emit("update:modelValue", { ...props.modelValue, [field]: value });
}
</script>

<template>
    <div class="space-y-4">
        <AppInput
            :model-value="form.title"
            :label="t('backend.studio.space_content.title')"
            :placeholder="t('backend.studio.space_content.title_placeholder')"
            :error="errors.title"
            required
            v-on:update:model-value="set('title', $event)"
        />

        <AppTextarea
            :model-value="form.body"
            :label="t('backend.studio.space_content.body')"
            :placeholder="t('backend.studio.space_content.body_placeholder')"
            :error="errors.body"
            :rows="6"
            v-on:update:model-value="set('body', $event)"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <AppSelect
                :model-value="String(form.columnId ?? '')"
                :label="t('backend.studio.space_content.column')"
                :options="columnOptions"
                :error="errors.columnId"
                required
                v-on:update:model-value="set('columnId', $event)"
            />

            <div class="flex flex-col gap-1.5">
                <label
                    class="text-sm font-medium text-primary"
                    for="space-content-scheduled-at"
                >
                    {{ t("backend.studio.space_content.scheduled_at") }}
                </label>
                <!-- A native picker rather than `AppInput`: a date and an
                     hour typed as free text is two parsers and a locale
                     argument, and every browser already ships the control. The
                     placeholder is what the field's own chrome does not say -
                     the shape a typed value takes when somebody types it. -->
                <input
                    id="space-content-scheduled-at"
                    type="datetime-local"
                    :value="form.scheduledAt"
                    :placeholder="t('backend.studio.space_content.scheduled_at_placeholder')"
                    class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary transition focus:border-accent-500 focus:ring-1 focus:ring-accent-500"
                    v-on:input="set('scheduledAt', $event.target.value)"
                >
                <p v-if="errors.scheduledAt" class="text-xs text-red-500">
                    {{ errors.scheduledAt }}
                </p>
                <p v-else class="text-xs text-muted">
                    {{ t("backend.studio.space_content.scheduled_at_hint") }}
                </p>
            </div>
        </div>

        <p class="text-xs text-muted">
            {{ t("backend.studio.space_content.timezone_notice", { timezone }) }}
        </p>
    </div>
</template>
