<script setup>
/**
 * Ajouter quelque chose au carnet : une note ou un dossier, au même endroit.
 *
 * Le plus d'un dossier créait une note, et seulement une note : pour ranger un
 * sous-dossier, il fallait passer par la bibliothèque, qui n'existe pas quand
 * une note est ouverte. Craft et Notion posent la question au moment du geste,
 * « quoi, et où », et c'est ce que fait cette modale : un choix, un nom, et
 * l'endroit écrit en toutes lettres pour qu'on ne range pas à l'aveugle.
 *
 * **Le nom est facultatif pour une note.** Une note sans titre s'ouvre et se
 * nomme en écrivant, comme avant ; un dossier, lui, se range par son nom, donc
 * il en demande un.
 */
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronRight, FileText, Folder, FolderPlus, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppColorPicker from "@/shared/components/form/picker/AppColorPicker.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { folderPath } from "../composables/noteBreadcrumb.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** Où ranger ; `null` pour la racine du carnet. */
    folderId: { type: Number, default: null },
    /** Les dossiers de la personne, pour écrire l'endroit. */
    folders: { type: Array, default: () => [] },
    /** Ce que la modale propose en premier. */
    initialKind: { type: String, default: "note" },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "submit"]);

const { t } = useI18n();

const kind = ref(props.initialKind);
const name = ref("");
const color = ref(null);
const nameInput = ref(null);

const isFolder = computed(() => "folder" === kind.value);
const path = computed(() => folderPath(props.folders, props.folderId));

// Chaque ouverture repart de zéro : une modale qui garde le nom d'un dossier
// créé il y a cinq minutes invite à en créer un second par erreur.
watch(
    () => props.show,
    async (open) => {
        if (!open) return;

        kind.value = props.initialKind;
        name.value = "";
        color.value = null;

        await nextTick();
        nameInput.value?.focus();
    },
    { immediate: true },
);

// Changer de nature garde le curseur dans le champ : on choisit, on tape.
function pick(value) {
    kind.value = value;
    nextTick(() => nameInput.value?.focus());
}

const canSubmit = computed(
    () => !props.saving && (!isFolder.value || "" !== name.value.trim()),
);

function submit() {
    if (!canSubmit.value) return;

    emit("submit", {
        kind: kind.value,
        name: name.value.trim(),
        color: isFolder.value ? color.value : null,
    });
}

const choices = computed(() => [
    { value: "note", label: t("notes.markdown.add.note"), icon: FileText },
    { value: "folder", label: t("notes.markdown.add.folder"), icon: Folder },
]);
</script>

<template>
    <AppModal
        :show="show"
        max-width="sm"
        :closeable="!saving"
        :title="t('notes.markdown.add.title')"
        :icon="FolderPlus"
        v-on:close="emit('close')"
    >
        <!-- Le groupe d'onglets de la maison, pas deux boutons improvisés. -->
        <div
            class="inline-flex rounded-lg border border-line bg-surface-2/40 p-0.5"
            role="radiogroup"
            :aria-label="t('notes.markdown.add.kind')"
        >
            <button
                v-for="choice in choices"
                :key="choice.value"
                type="button"
                role="radio"
                :aria-checked="kind === choice.value"
                :data-add-kind="choice.value"
                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition-colors"
                :class="kind === choice.value ? 'bg-surface font-medium text-primary shadow-sm' : 'text-muted hover:text-primary'"
                v-on:click="pick(choice.value)"
            >
                <component :is="choice.icon" class="h-3.5 w-3.5" :stroke-width="2" />
                {{ choice.label }}
            </button>
        </div>

        <AppInput
            ref="nameInput"
            v-model="name"
            data-add-name
            class="mt-4 w-full"
            :placeholder="isFolder ? t('notes.markdown.folders.name_placeholder') : t('notes.markdown.title_placeholder')"
            v-on:keydown.enter.prevent="submit"
        />

        <!-- L'endroit, écrit : on range là où l'on a cliqué, et le dire évite
             de chercher ensuite où la note est partie. -->
        <p class="mt-3 flex min-w-0 flex-wrap items-center gap-1 text-xs text-muted" data-add-where>
            <span>{{ t('notes.markdown.add.where') }}</span>
            <span class="font-medium text-secondary">{{ t('notes.markdown.library.title') }}</span>
            <template v-for="crumb in path" :key="crumb.id">
                <ChevronRight class="h-3 w-3 shrink-0" :stroke-width="2" />
                <span class="truncate font-medium text-secondary">
                    {{ crumb.name || t('notes.markdown.folders.untitled') }}
                </span>
            </template>
        </p>

        <AppColorPicker
            v-if="isFolder"
            v-model="color"
            class="mt-4"
            :label="t('notes.markdown.folders.color')"
        />

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" :disabled="saving" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.cancel') }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    data-add-submit
                    :loading="saving"
                    :disabled="!canSubmit"
                    v-on:click="submit"
                >
                    {{ isFolder ? t('notes.markdown.add.create_folder') : t('notes.markdown.add.create_note') }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
