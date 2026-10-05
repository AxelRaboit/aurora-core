<script setup>
/**
 * La confirmation avant de mettre un livrable à la corbeille, la même partout :
 * depuis une liste ou depuis l'éditeur. Il n'est pas détruit : il se restaure
 * depuis la corbeille, jusqu'à sa suppression définitive.
 */
import { useI18n } from "vue-i18n";
import { Trash2, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: "" },
    deleting: { type: Boolean, default: false },
});

defineEmits(["cancel", "confirm"]);

const { t } = useI18n();
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        :title="t('suite.studio.deliverables.trash_action')"
        :icon="Trash2"
        v-on:close="$emit('cancel')"
    >
        <p class="m-0 text-sm text-primary">{{ t("suite.studio.deliverables.delete_confirm", { title }) }}</p>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="$emit('cancel')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="danger" size="md" :loading="deleting" v-on:click="$emit('confirm')">
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.studio.deliverables.trash_action") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
