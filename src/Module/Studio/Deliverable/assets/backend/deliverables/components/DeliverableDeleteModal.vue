<script setup>
/**
 * La confirmation avant de supprimer un livrable, la même partout : depuis une
 * liste ou depuis l'éditeur.
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
        :closeable="false"
        :title="t('shared.common.delete')"
        :icon="Trash2"
        v-on:close="$emit('cancel')"
    >
        <p class="m-0 text-sm text-primary">{{ t("backend.studio.deliverables.delete_confirm", { title }) }}</p>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="$emit('cancel')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="danger" size="md" :loading="deleting" v-on:click="$emit('confirm')">
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
