<script setup>
import { useI18n } from "vue-i18n";
import { Plus, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { iconForType } from "./fieldTypeIcons.js";

/**
 * A question's first gesture: saying what shape it is.
 *
 * **Before the label, and as cards.** The type decided all the rest of the
 * settings (choices to enter or not, a placeholder text or not), and it was
 * hidden in a dropdown at the top of an already full dialog. Here it is chosen
 * while seeing each shape and what it does, then the panel opens on what is
 * specific to it.
 */
defineProps({
    show: { type: Boolean, default: false },
    fieldTypes: { type: Array, default: () => [] },
});

const emit = defineEmits(["close", "pick"]);

const { t } = useI18n();
</script>

<template>
    <AppModal
        :show="show"
        max-width="2xl"
        :title="t('suite.forms.fields.pick_type')"
        :icon="Plus"
        v-on:close="emit('close')"
    >
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <button
                v-for="type in fieldTypes"
                :key="type.value"
                type="button"
                class="flex items-start gap-3 rounded-lg border border-line p-3 text-left transition-colors hover:border-accent-500 hover:bg-accent-600/10"
                v-on:click="emit('pick', type.value)"
            >
                <component :is="iconForType(type.value)" class="mt-0.5 h-4 w-4 shrink-0 text-accent-400" :stroke-width="2" />
                <span class="min-w-0">
                    <span class="block text-sm font-medium text-primary">{{ t(type.labelKey) }}</span>
                    <span class="mt-0.5 block text-xs text-muted">{{ t(`suite.forms.field_type_hints.${type.value}`) }}</span>
                </span>
            </button>
        </div>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
