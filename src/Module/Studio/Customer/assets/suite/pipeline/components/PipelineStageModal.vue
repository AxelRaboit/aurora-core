<script setup>
/**
 * Creating or editing a stage of the pipeline: its name, its colour, and
 * whether it is one of the two outcomes.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Columns3, Save, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppColourSlotPicker from "@/shared/components/form/picker/AppColourSlotPicker.vue";

const props = defineProps({
    /** `{id, name, colourSlot, role}`, or null when closed. */
    form: { type: Object, default: null },
    errors: { type: Object, default: () => ({}) },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["update:form", "close", "submit"]);

const { t } = useI18n();

const roleOptions = computed(() => [
    { value: "", label: t("suite.studio.pipeline.stage_roles.none") },
    { value: "won", label: t("suite.studio.pipeline.stage_roles.won") },
    { value: "lost", label: t("suite.studio.pipeline.stage_roles.lost") },
]);

function set(field, value) {
    emit("update:form", { ...props.form, [field]: value });
}
</script>

<template>
    <AppModal
        :show="!!form"
        max-width="sm"
        :title="form?.id ? t('suite.studio.pipeline.edit_stage') : t('suite.studio.pipeline.add_stage')"
        :icon="Columns3"
        :closeable="false"
        v-on:close="emit('close')"
    >
        <form v-if="form" class="space-y-4" v-on:submit.prevent="emit('submit')">
            <AppInput
                :model-value="form.name"
                :label="t('suite.studio.pipeline.stage_name')"
                :placeholder="t('suite.studio.pipeline.stage_name_placeholder')"
                :error="errors.name"
                required
                v-on:update:model-value="set('name', $event)"
            />
            <AppColourSlotPicker
                :model-value="form.colourSlot"
                :label="t('suite.studio.pipeline.stage_colour')"
                clearable
                v-on:update:model-value="set('colourSlot', $event)"
            />
            <AppSelect
                :model-value="form.role"
                :label="t('suite.studio.pipeline.stage_role')"
                :options="roleOptions"
                :hint="t('suite.studio.pipeline.stage_role_hint')"
                :error="errors.role || errors.stage"
                v-on:update:model-value="set('role', $event)"
            />
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
