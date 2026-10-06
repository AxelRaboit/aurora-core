<script setup>
/**
 * The dialog that turns a prospect into a customer.
 *
 * **A single field**, and it is the only one the status requires: the
 * address the contract goes to. Asking for the SIRET, the legal form and the
 * head office at the same moment would be asking for the whole sheet again
 * to change one column, and that is exactly what the conversion must avoid -
 * you convert when the person says yes, not when you have finished filling
 * in their file.
 *
 * The sentence under the field says so, so nobody thinks they forgot
 * something: the rest is added on the sheet, when it is known.
 */
import { useI18n } from "vue-i18n";
import { BadgeCheck, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";

defineProps({
    show: { type: Boolean, default: false },
    /** The converted company, to name it in the title. */
    name: { type: String, default: "" },
    modelValue: { type: String, default: "" },
    error: { type: String, default: "" },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "close", "submit"]);

const { t } = useI18n();
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        :title="t('suite.studio.customers.convert_title', { name })"
        :icon="BadgeCheck"
        :closeable="false"
        v-on:close="emit('close')"
    >
        <form class="space-y-4" v-on:submit.prevent="emit('submit')">
            <AppInput
                :model-value="modelValue"
                type="email"
                :label="t('suite.studio.customers.contractual_email')"
                :placeholder="t('shared.placeholders.email')"
                :hint="t('suite.studio.customers.convert_hint')"
                :error="error"
                required
                v-on:update:model-value="emit('update:modelValue', $event)"
            />
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    :loading="loading"
                    v-on:click="emit('submit')"
                >
                    <BadgeCheck class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("suite.studio.customers.convert") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
