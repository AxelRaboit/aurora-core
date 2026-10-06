<script setup>
/**
 * La confirmation avant de supprimer un client, la même depuis la liste et
 * depuis sa page.
 *
 * Elle prévient de la garde avant qu'on la rencontre : un client nommé par un
 * contrat ou par un espace, même à la corbeille, ne se supprime pas, et le
 * serveur le refuse en le disant.
 */
import { useI18n } from "vue-i18n";
import { Trash2, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";

defineProps({
    show: { type: Boolean, default: false },
    name: { type: String, default: "" },
    loading: { type: Boolean, default: false },
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
        <p class="text-sm text-primary">
            {{ t("suite.studio.customers.delete_confirm", { name }) }}
        </p>
        <p class="text-sm text-secondary">
            {{ t("suite.studio.customers.delete_warning") }}
        </p>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="$emit('cancel')">
                    <X class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="danger" size="md" :loading="loading" v-on:click="$emit('confirm')">
                    <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("shared.common.delete") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
