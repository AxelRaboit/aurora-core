<script setup>
/**
 * La fenêtre qui transforme un prospect en client.
 *
 * **Un seul champ**, et c'est le seul que le statut impose : l'adresse où part
 * le contrat. Demander le SIRET, la forme juridique et le siège au même moment
 * serait redemander la fiche entière pour changer une colonne, et c'est
 * exactement ce que la conversion doit éviter - on convertit quand la personne
 * dit oui, pas quand on a fini de remplir son dossier.
 *
 * La phrase sous le champ le dit, pour que personne ne croie avoir oublié
 * quelque chose : le reste s'ajoute sur la fiche, quand on l'a.
 */
import { useI18n } from "vue-i18n";
import { BadgeCheck, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";

defineProps({
    show: { type: Boolean, default: false },
    /** La société convertie, pour la nommer dans le titre. */
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
        :title="t('backend.studio.customers.convert_title', { name })"
        :icon="BadgeCheck"
        :closeable="false"
        v-on:close="emit('close')"
    >
        <form class="space-y-4" v-on:submit.prevent="emit('submit')">
            <AppInput
                :model-value="modelValue"
                type="email"
                :label="t('backend.studio.customers.contractual_email')"
                :placeholder="t('shared.placeholders.email')"
                :hint="t('backend.studio.customers.convert_hint')"
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
                    {{ t("backend.studio.customers.convert") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
