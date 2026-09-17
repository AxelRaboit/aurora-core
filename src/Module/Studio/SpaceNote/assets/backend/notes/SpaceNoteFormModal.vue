<script setup>
/**
 * Écrire une note.
 *
 * Le même éditeur de blocs que les publications, avec ses outils et ses images
 * - rien de propre aux notes, parce qu'une note est du texte riche comme le
 * reste et qu'un second éditeur aurait été un second jeu d'habitudes.
 *
 * **L'adresse d'envoi des images est celle de l'espace**, pas celle des images
 * d'édition en général : une capture collée ici parle d'un client, elle se
 * range dans le dossier de cet espace et reste hors du catch-all public. C'est
 * la seule chose que ce composant a à dire à l'éditeur.
 */
import { useI18n } from "vue-i18n";
import { Save, StickyNote, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppColourSlotPicker from "@/shared/components/form/picker/AppColourSlotPicker.vue";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    loading: { type: Boolean, default: false },
    editing: { type: Boolean, default: false },
    /** Où l'éditeur dépose ses images : le dossier de cet espace. */
    uploadUrl: { type: String, required: true },
});

const emit = defineEmits(["update:modelValue", "close", "submit"]);

const { t } = useI18n();

function set(field, value) {
    emit("update:modelValue", { ...props.modelValue, [field]: value });
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="3xl"
        :title="t(editing ? 'backend.studio.space_notes.edit' : 'backend.studio.space_notes.create')"
        :icon="StickyNote"
        :closeable="false"
        v-on:close="emit('close')"
    >
        <div class="space-y-4">
            <AppInput
                :model-value="modelValue.title"
                :label="t('backend.studio.space_notes.title')"
                :placeholder="t('backend.studio.space_notes.title_placeholder')"
                :error="errors.title"
                required
                v-on:update:model-value="set('title', $event)"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <AppColourSlotPicker
                    :model-value="modelValue.colourSlot"
                    clearable
                    :label="t('backend.studio.space_notes.colour')"
                    :hint="t('backend.studio.space_notes.colour_hint')"
                    :error="errors.colourSlot"
                    v-on:update:model-value="set('colourSlot', $event)"
                />
                <div class="flex items-end pb-2">
                    <AppCheckbox
                        :model-value="modelValue.pinned"
                        :label="t('backend.studio.space_notes.pinned')"
                        v-on:update:model-value="set('pinned', $event)"
                    />
                </div>
            </div>

            <!-- Remonté par sa clé à chaque ouverture : l'éditeur lit sa valeur
                 au montage, et rouvrir une autre note sur la même instance
                 afficherait la précédente. -->
            <AppBlockEditor
                :key="editing ? 'edit' : 'create'"
                :model-value="modelValue.body"
                :upload-url="uploadUrl"
                :placeholder="t('backend.studio.space_notes.body_placeholder')"
                v-on:update:model-value="set('body', $event)"
            />
        </div>

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
