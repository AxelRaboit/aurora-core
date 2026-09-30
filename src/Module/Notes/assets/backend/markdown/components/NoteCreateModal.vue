<script setup>
/**
 * Ajouter quelque chose au carnet : une note, un dossier, ou un espace.
 *
 * Le plus d'un dossier créait une note, et seulement une note : pour ranger un
 * sous-dossier, il fallait passer par la bibliothèque, qui n'existe pas quand
 * une note est ouverte. Craft et Notion posent la question au moment du geste,
 * « quoi, et où », et c'est ce que fait cette modale : un choix, un nom, et
 * l'endroit écrit en toutes lettres pour qu'on ne range pas à l'aveugle.
 *
 * **Le nom est facultatif pour une note.** Une note sans titre s'ouvre et se
 * nomme en écrivant, comme avant ; un dossier et un espace, eux, se rangent
 * par leur nom, donc ils en demandent un.
 *
 * **Un espace se crée ici aussi**, pour qui en a le droit : c'est au moment
 * où l'on veut ranger quelque chose à part qu'on se demande qui le verra. Le
 * nom, puis qui y entre - personne d'autre, des personnes choisies, ou tout
 * le back-office -, et ce qu'on y fait quand on y entre sans y être inscrit.
 */
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronRight, FileText, Folder, FolderPlus, Layers, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppColorPicker from "@/shared/components/form/picker/AppColorPicker.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { folderPath } from "../composables/noteBreadcrumb.js";
import { spaceLabel } from "../composables/noteSpaces.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** Où ranger ; `null` pour la racine d'un espace. */
    folderId: { type: Number, default: null },
    /** La racine visée quand il n'y a pas de dossier ; celle de son espace à défaut. */
    spaceId: { type: Number, default: null },
    /** Les dossiers lisibles, pour écrire l'endroit. */
    folders: { type: Array, default: () => [] },
    /** Les espaces lisibles, avec le rôle de la personne dans chacun. */
    spaces: { type: Array, default: () => [] },
    /** Le droit de créer un espace partagé. */
    canCreateSpace: { type: Boolean, default: false },
    /** Ce que la modale propose en premier. */
    initialKind: { type: String, default: "note" },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "submit"]);

const { t } = useI18n();

const kind = ref(props.initialKind);
const name = ref("");
const color = ref(null);
const access = ref("backoffice");
const defaultRole = ref("reader");
const chosenSpaceId = ref(null);
const nameInput = ref(null);

const isFolder = computed(() => "folder" === kind.value);
const isSpace = computed(() => "space" === kind.value);
const path = computed(() => folderPath(props.folders, props.folderId));

/** Les espaces où l'on peut écrire : les seuls où ranger. */
const writableSpaces = computed(() => props.spaces.filter((space) => space.canWrite));

/**
 * L'espace de destination : celui du dossier quand il y en a un, sinon celui
 * qu'on a choisi, sinon le sien.
 */
const targetSpaceId = computed(() => {
    if (null !== props.folderId) {
        const folder = props.folders.find((one) => Number(one.id) === props.folderId);

        return null == folder?.spaceId ? null : Number(folder.spaceId);
    }

    return chosenSpaceId.value;
});

const targetSpace = computed(() =>
    props.spaces.find((space) => Number(space.id) === targetSpaceId.value) ?? null,
);

/** Plusieurs espaces où écrire, et rien qui impose le sien : on laisse choisir. */
const canPickSpace = computed(() => null === props.folderId && writableSpaces.value.length > 1);

const spaceOptions = computed(() =>
    writableSpaces.value.map((space) => ({ value: space.id, label: spaceLabel(space, t) })),
);

function defaultSpaceId() {
    if (null !== props.spaceId) return props.spaceId;

    const personal = props.spaces.find((space) => space.personal);

    return null == personal ? null : Number(personal.id);
}

// Chaque ouverture repart de zéro : une modale qui garde le nom d'un dossier
// créé il y a cinq minutes invite à en créer un second par erreur.
watch(
    () => props.show,
    async (open) => {
        if (!open) return;

        kind.value = props.initialKind;
        name.value = "";
        color.value = null;
        access.value = "backoffice";
        defaultRole.value = "reader";
        chosenSpaceId.value = defaultSpaceId();

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
    () => !props.saving && ("note" === kind.value || "" !== name.value.trim()),
);

function submit() {
    if (!canSubmit.value) return;

    emit("submit", {
        kind: kind.value,
        name: name.value.trim(),
        color: "note" === kind.value ? null : color.value,
        spaceId: targetSpaceId.value,
        access: access.value,
        defaultRole: defaultRole.value,
    });
}

const choices = computed(() => [
    { value: "note", label: t("notes.markdown.add.note"), icon: FileText },
    { value: "folder", label: t("notes.markdown.add.folder"), icon: Folder },
    ...(props.canCreateSpace ? [{ value: "space", label: t("notes.markdown.add.space"), icon: Layers }] : []),
]);

const accessOptions = computed(() => [
    { value: "private", label: t("notes.markdown.spaces.access.private") },
    { value: "members", label: t("notes.markdown.spaces.access.members") },
    { value: "backoffice", label: t("notes.markdown.spaces.access.backoffice") },
]);

const roleOptions = computed(() => [
    { value: "reader", label: t("notes.markdown.spaces.role.reader") },
    { value: "editor", label: t("notes.markdown.spaces.role.editor") },
]);

const accessHint = computed(() => t(`notes.markdown.spaces.access.${access.value}_hint`));

const submitLabel = computed(() => {
    if (isSpace.value) return t("notes.markdown.add.create_space");

    return isFolder.value ? t("notes.markdown.add.create_folder") : t("notes.markdown.add.create_note");
});

const placeholder = computed(() => {
    if (isSpace.value) return t("notes.markdown.spaces.name_placeholder");

    return isFolder.value ? t("notes.markdown.folders.name_placeholder") : t("notes.markdown.title_placeholder");
});
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
            :placeholder="placeholder"
            v-on:keydown.enter.prevent="submit"
        />

        <template v-if="isSpace">
            <AppChoiceRow
                v-model="access"
                data-add-access
                class="mt-4"
                :label="t('notes.markdown.spaces.access.label')"
                :options="accessOptions"
            />
            <p class="mt-1 text-xs text-muted">{{ accessHint }}</p>

            <!-- Le rôle de qui entre sans être inscrit n'a de sens que quand
                 tout le back-office entre : ailleurs, chacun a le sien. -->
            <AppChoiceRow
                v-if="'backoffice' === access"
                v-model="defaultRole"
                data-add-role
                class="mt-4"
                :label="t('notes.markdown.spaces.role.label')"
                :options="roleOptions"
            />
        </template>

        <template v-else>
            <AppSelect
                v-if="canPickSpace"
                data-add-space
                class="mt-4"
                :label="t('notes.markdown.spaces.label')"
                :model-value="chosenSpaceId"
                :options="spaceOptions"
                v-on:update:model-value="chosenSpaceId = Number($event)"
            />

            <!-- L'endroit, écrit : on range là où l'on a cliqué, et le dire
                 évite de chercher ensuite où la note est partie. -->
            <p class="mt-3 flex min-w-0 flex-wrap items-center gap-1 text-xs text-muted" data-add-where>
                <span>{{ t('notes.markdown.add.where') }}</span>
                <span class="font-medium text-secondary">
                    {{ targetSpace ? spaceLabel(targetSpace, t) : t('notes.markdown.library.title') }}
                </span>
                <template v-for="crumb in path" :key="crumb.id">
                    <ChevronRight class="h-3 w-3 shrink-0" :stroke-width="2" />
                    <span class="truncate font-medium text-secondary">
                        {{ crumb.name || t('notes.markdown.folders.untitled') }}
                    </span>
                </template>
            </p>
        </template>

        <AppColorPicker
            v-if="'note' !== kind"
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
                    {{ submitLabel }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
