<script setup>
/**
 * Add something to the notebook: a note, a folder, or a space.
 *
 * A folder's plus created a note, and only a note: to file a subfolder, one
 * had to go through the library, which does not exist when a note is open.
 * Craft and Notion ask the question at the moment of the gesture, "what, and
 * where", and that is what this modal does: a choice, a name, and the place
 * written out in full so that nothing is filed blindly.
 *
 * **The name is optional for a note.** An untitled note opens and gets named
 * while writing, as before; a folder and a space are filed by their name, so
 * they ask for one.
 *
 * **A space is created here too**, for whoever is allowed to: it is when one
 * wants to file something apart that one wonders who will see it. The name,
 * then who gets in - nobody else, chosen people, or the whole back-office -,
 * and what one does there when entering without being a member.
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
    /** Where to file; `null` for a space's root. */
    folderId: { type: Number, default: null },
    /** The targeted root when there is no folder; one's own space's otherwise. */
    spaceId: { type: Number, default: null },
    /** The readable folders, to write out the place. */
    folders: { type: Array, default: () => [] },
    /** The readable spaces, with the person's role in each. */
    spaces: { type: Array, default: () => [] },
    /** The right to create a shared space. */
    canCreateSpace: { type: Boolean, default: false },
    /** What the modal offers first. */
    initialKind: { type: String, default: "note" },
    /** The templates one can read, `{id, title}`: a note can start from one of them. */
    templates: { type: Array, default: () => [] },
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
/** The marker a template replaces with today's date (server side). */
const DATE_MARKER = "{{date}}";

/** The chosen template, `null` for an empty note. */
const templateId = ref(null);
const nameInput = ref(null);

const isFolder = computed(() => "folder" === kind.value);
const isSpace = computed(() => "space" === kind.value);
const path = computed(() => folderPath(props.folders, props.folderId));

/** The spaces one can write in: the only ones to file into. */
const writableSpaces = computed(() => props.spaces.filter((space) => space.canWrite));

/**
 * The destination space: the folder's when there is one, otherwise the one
 * chosen, otherwise one's own.
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

/** Several spaces to write in, and nothing imposing one's own: we let the person choose. */
const canPickSpace = computed(() => null === props.folderId && writableSpaces.value.length > 1);

const spaceOptions = computed(() =>
    writableSpaces.value.map((space) => ({ value: space.id, label: spaceLabel(space, t) })),
);

function defaultSpaceId() {
    if (null !== props.spaceId) return props.spaceId;

    const personal = props.spaces.find((space) => space.personal);

    return null == personal ? null : Number(personal.id);
}

// Each opening starts from scratch: a modal that keeps the name of a folder
// created five minutes ago invites creating a second one by mistake.
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
        templateId.value = null;

        await nextTick();
        nameInput.value?.focus();
    },
    { immediate: true },
);

// Changing kind keeps the cursor in the field: one chooses, one types.
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
        templateId: "note" === kind.value ? templateId.value : null,
    });
}

/** "Une note vide", then each template by its title. */
const templateOptions = computed(() => [
    { value: "", label: t("notes.markdown.template.blank") },
    ...props.templates.map((one) => ({ value: String(one.id), label: one.title || t("notes.markdown.untitled") })),
]);

function pickTemplate(value) {
    templateId.value = "" === value || null == value ? null : Number(value);
    nextTick(() => nameInput.value?.focus());
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
        <!-- The house tab group, not two improvised buttons. -->
        <div
            class="inline-flex aurora-segmented"
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

            <!-- The role of whoever enters without being a member only makes
                 sense when the whole back-office gets in: elsewhere, each has
                 their own. -->
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
            <!-- Start from a template: a brief, meeting notes, a procedure
                 ready to fill. The typed name becomes the title, the
                 template's one otherwise. -->
            <template v-if="'note' === kind && templates.length">
                <AppSelect
                    data-add-template
                    class="mt-4"
                    :label="t('notes.markdown.template.start_from')"
                    :model-value="null === templateId ? '' : String(templateId)"
                    :options="templateOptions"
                    v-on:update:model-value="pickTemplate"
                />
                <p v-if="null !== templateId" class="mt-1 text-xs text-muted">
                    {{ t('notes.markdown.template.hint', { marker: DATE_MARKER }) }}
                </p>
            </template>

            <AppSelect
                v-if="canPickSpace"
                data-add-space
                class="mt-4"
                :label="t('notes.markdown.spaces.label')"
                :model-value="chosenSpaceId"
                :options="spaceOptions"
                v-on:update:model-value="chosenSpaceId = Number($event)"
            />

            <!-- The place, written out: we file where one clicked, and saying
                 it avoids searching afterwards for where the note went. -->
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
