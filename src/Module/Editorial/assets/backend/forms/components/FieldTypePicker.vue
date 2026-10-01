<script setup>
import { useI18n } from "vue-i18n";
import { Plus, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { iconForType } from "./fieldTypeIcons.js";

/**
 * Le premier geste d'une question : dire de quelle forme elle est.
 *
 * **Avant le libellé, et en cartes.** Le type décidait de tout le reste du
 * réglage (des choix à saisir ou non, un texte d'exemple ou non), et il était
 * caché dans une liste déroulante en haut d'une fenêtre déjà pleine. Ici on le
 * choisit en voyant chaque forme et ce qu'elle fait, puis le panneau s'ouvre
 * sur ce qui lui est propre.
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
        :title="t('backend.forms.fields.pick_type')"
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
                    <span class="mt-0.5 block text-xs text-muted">{{ t(`backend.forms.field_type_hints.${type.value}`) }}</span>
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
