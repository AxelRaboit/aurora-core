<script setup>
/**
 * A prospect dropped on the lost stage: why.
 *
 * Optional, and said so: the reason is worth having - it is what, months
 * later, tells a price problem from a timing one - but refusing the move
 * without it would only teach people to type "x".
 */
import { useI18n } from "vue-i18n";
import { ThumbsDown, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";

defineProps({
    show: { type: Boolean, default: false },
    name: { type: String, default: "" },
    modelValue: { type: String, default: "" },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue", "close", "submit"]);

const { t } = useI18n();
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        :title="t('suite.studio.pipeline.lost_title', { name })"
        :icon="ThumbsDown"
        :closeable="false"
        v-on:close="emit('close')"
    >
        <form class="space-y-4" v-on:submit.prevent="emit('submit')">
            <AppInput
                :model-value="modelValue"
                :label="t('suite.studio.pipeline.lost_reason')"
                :placeholder="t('suite.studio.pipeline.lost_reason_placeholder')"
                :hint="t('suite.studio.pipeline.lost_reason_hint')"
                maxlength="255"
                v-on:update:model-value="emit('update:modelValue', $event)"
            />
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="loading" v-on:click="emit('submit')">
                    <ThumbsDown class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("suite.studio.pipeline.mark_lost") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
