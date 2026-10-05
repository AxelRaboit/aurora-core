<script setup>
/**
 * Choisir un dossier Drive : celui du client ou celui de l'agence.
 *
 * Une seule fenêtre pour les deux, parce que c'est le même geste : coller
 * l'adresse d'un dossier partagé avec le compte de service, enregistrer, ou
 * débrancher. Les deux cartes des réglages ne diffèrent que par ce qu'elles
 * disent ; la fenêtre ne se dessine pas deux fois.
 *
 * L'adresse avec laquelle partager est rappelée sous le champ, avec un bouton
 * pour la copier : c'est elle qu'on va coller dans le partage de Google Drive,
 * ou envoyer au client.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Copy, Save, Unlink, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { useClipboard } from "@/shared/composables/useClipboard.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    intro: { type: String, default: "" },
    /** L'adresse du dossier branché, pour la reprendre ; vide s'il n'y en a pas. */
    currentUrl: { type: String, default: "" },
    serviceAccountEmail: { type: String, default: null },
    error: { type: String, default: "" },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "save", "unlink"]);

const { t } = useI18n();
const { copy } = useClipboard();

const value = ref("");

// Repris à chaque ouverture : une saisie abandonnée ne revient pas la fois
// suivante comme si elle avait été enregistrée.
watch(
    () => props.show,
    (open) => {
        if (open) value.value = props.currentUrl;
    },
    { immediate: true },
);
</script>

<template>
    <AppModal :show="show" max-width="md" v-on:close="emit('close')">
        <h3 class="mb-1 text-sm font-semibold text-primary">{{ title }}</h3>
        <p v-if="intro" class="mb-3 text-xs text-muted">{{ intro }}</p>

        <AppInput
            v-model="value"
            type="text"
            :label="t('suite.studio.spaces.settings.folder_field')"
            placeholder="https://drive.google.com/drive/folders/…"
            :error="error"
        />

        <div class="mt-3 flex flex-col gap-1.5 rounded-lg border border-line bg-surface-2 p-3">
            <p class="m-0 text-xs text-muted">
                {{ t(serviceAccountEmail ? "suite.studio.spaces.settings.share_with" : "suite.studio.spaces.settings.share_unknown") }}
            </p>
            <div v-if="serviceAccountEmail" class="flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate rounded bg-surface px-2 py-1 font-mono text-xs text-primary">{{ serviceAccountEmail }}</code>
                <AppButton
                    variant="secondary"
                    size="sm"
                    :label="t('suite.studio.spaces.settings.copy_email')"
                    icon-only-on-phone
                    v-on:click="copy(serviceAccountEmail, 'suite.studio.spaces.settings.email_copied')"
                >
                    <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                </AppButton>
            </div>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton
                    v-if="currentUrl"
                    variant="ghost"
                    size="md"
                    class="sm:mr-auto"
                    :disabled="saving"
                    v-on:click="emit('unlink')"
                >
                    <Unlink class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.studio.spaces.settings.folder_unlink") }}
                </AppButton>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    :loading="saving"
                    :disabled="!value.trim()"
                    v-on:click="emit('save', value)"
                >
                    <Save class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
