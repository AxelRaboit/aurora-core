<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppPageHeading from "@/shared/components/display/AppPageHeading.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppPageBar from "@/shared/components/nav/AppPageBar.vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useDocumentsForm, DOCUMENT_STATUS_BADGE } from "./composables/useDocumentsForm.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import DocumentStorageChip from "@ged/suite/documents/components/DocumentStorageChip.vue";
import { useDocumentRelocation } from "./composables/useDocumentRelocation.js";
import { useDocumentRowActions } from "./composables/useDocumentRowActions.js";
import { byPosition, withDepthLabel } from "./composables/useDocumentSidebarTree.js";
import { buildFolderTree, flattenFolders } from "@/shared/utils/tree/folderTree.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { Pencil, Trash2, Download, FileText, Folder, Tag, Save, X, Paperclip, Crop, RotateCcw } from "lucide-vue-next";
import AppImagePreview from "@/shared/components/display/AppImagePreview.vue";
import ImageCropperModal from "@/shared/components/overlay/ImageCropperModal.vue";
import DocumentTagChip from "@ged/suite/documents/components/DocumentTagChip.vue";
import DocumentFamilyStrip from "@ged/suite/documents/components/DocumentFamilyStrip.vue";

const { t } = useI18n();
const { can } = usePrivileges();
const { formatDate } = useDateFormat();

const props = defineProps({
    document: { type: Object, required: true },
    backPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    restorePath: { type: String, default: "" },
    cropPath: { type: String, required: true },
    listPath: { type: String, required: true },
    storagePath: { type: String, default: "" },
    storageRelocationAvailable: { type: Boolean, default: false },
    alternatesPath: { type: String, default: "" },
    showPath: { type: String, default: "" },
    /** What to file the document with, as in the media library. */
    categories: { type: Array, default: () => [] },
    tags: { type: Array, default: () => [] },
    folders: { type: Array, default: () => [] },
});

// The "Modifier" window files the document like the media library one does:
// category, tags and folder, and not only its title and status.
const categoryOptions = computed(() => props.categories.map((category) => ({ value: category.id, label: category.name })));
const tagOptions = computed(() => props.tags.map((tag) => ({ value: tag.id, label: tag.name })));
const folderOptions = computed(() => withDepthLabel(flattenFolders(buildFolderTree(props.folders, byPosition))));

// A member of the family opens on its own page, like this one.
function openMember(member) {
    if (props.showPath) window.location.href = buildPath(props.showPath, { id: member.id });
}

const currentDocument = ref({ ...props.document });

// The composable patches a list; here there is one document, so it is handed a
// list of one and reads the result back out. Cheaper than a second code path
// that could drift from the one the list screen uses.
const singleton = computed({
    get: () => [currentDocument.value],
    set: (rows) => {
        currentDocument.value = rows[0];
    },
});
const { relocate, relocatingId } = useDocumentRelocation(props, singleton);

const cropTarget = ref(null);

function onCropped(updatedDocument) {
    if (updatedDocument) currentDocument.value = { ...updatedDocument };
}

function onSaved() {
    window.location.reload();
}

function onDeleted() {
    window.location.href = props.listPath;
}

const {
    statusOptions,
    showEdit, editingDocument, editForm, editErrors, editLoading, openEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
} = useDocumentsForm(
    '',
    props.updatePath,
    props.deletePath,
    onSaved,
);

/**
 * The same list the library rows offer, minus the two entries that make no
 * sense here: opening the document is where the reader already is, and the QR
 * code belongs to the screen that hands out addresses.
 *
 * Read from the shared composable rather than written again: the header used
 * to spell out its own relocation direction and its own pending check, which
 * is the drift this list exists to avoid.
 */
/**
 * A trashed document still opens here, from a link or the trash screen. The page
 * says so and offers the way back, rather than showing it as live with a menu
 * that would trash it a second time.
 */
const { request } = useRequest();
const restoring = ref(false);

async function restore() {
    if (!props.restorePath || restoring.value) return;

    restoring.value = true;
    const data = await request(props.restorePath, {});
    restoring.value = false;
    if (!data?.success) return;

    toast.success(t("suite.trash.restored"));
    window.location.reload();
}

const actionsFor = useDocumentRowActions({
    can,
    openEdit,
    confirmDelete,
    relocate,
    relocationAvailable: props.storageRelocationAvailable,
    restore: props.restorePath ? restore : null,
});

const documentActions = computed(() => actionsFor(currentDocument.value));

function isImage(mimeType) {
    return mimeType?.startsWith('image/');
}

function isPdf(mimeType) {
    return mimeType === 'application/pdf';
}
</script>

<template>
    <div class="aurora-stack">
        <!-- Four buttons and a chip on a row that could not wrap: on a phone
             they were squeezed to slivers. The download stays reachable, and
             more than once - the file block further down offers it beside the
             preview, where somebody looking at the document already is. -->
        <AppPageBar :back-href="backPath" :back-label="t('suite.ged.documents.back_to_list')">
            <DocumentStorageChip
                v-if="storageRelocationAvailable"
                :disk="currentDocument.storageDisk"
                :state="currentDocument.storageTransferState"
                :error="currentDocument.storageTransferError"
            />
            <AppPageActions
                v-if="documentActions.length"
                :actions="documentActions"
                :label="currentDocument.title ?? ''"
                :busy="relocatingId === currentDocument.id"
                icon-only-on-phone
            />
        </AppPageBar>

        <!-- The title as the page opens, as on every record of the suite;
             the reference beside its status, the one people quote to each
             other (visual redesign of the suite, 10/10/2026). -->
        <AppPageHeading :title="currentDocument.title ?? ''" :subtitle="currentDocument.description ?? ''">
            <span v-if="currentDocument.reference" class="rounded bg-surface-2 px-1.5 py-0.5 font-mono text-xs text-muted">{{ currentDocument.reference }}</span>
            <AppBadge v-if="currentDocument.trashed" color="rose">{{ t("suite.ged.documents.trashed.badge") }}</AppBadge>
            <AppBadge v-else :color="DOCUMENT_STATUS_BADGE[currentDocument.status]">{{ currentDocument.statusLabel }}</AppBadge>
        </AppPageHeading>

        <AppMessage v-if="currentDocument.trashed" variant="trash">
            <p class="font-medium">{{ t("suite.ged.documents.trashed.title") }}</p>
            <p class="mt-0.5">{{ t("suite.ged.documents.trashed.body", { date: formatDate(currentDocument.deletedAt) }) }}</p>
            <template v-if="restorePath && can('ged.documents.delete')" #actions>
                <AppButton size="sm" variant="ghost" :loading="restoring" v-on:click="restore">
                    <RotateCcw class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.trash.restore") }}
                </AppButton>
            </template>
        </AppMessage>

        <!-- The screen's how-to guide, next to what it explains; folded
             or unfolded, the choice applies to every panel. -->
        <AppGuide :title="t('suite.ged.documents.show_guide.title')" storage-key="ged-document-show">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.ged.documents.show_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <!-- The file on the left, what describes it on the right and in view
             while the preview scrolls; one under the other elsewhere. -->
        <div class="grid grid-cols-1 items-start aurora-gap" :class="currentDocument.fileUrl ? 'lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]' : ''">
            <section v-if="currentDocument.fileUrl" class="aurora-card min-w-0 space-y-3 p-4 sm:p-5" data-document-file>
                <h3 class="m-0 text-[0.9375rem] font-semibold text-primary">{{ t("suite.ged.documents.file") }}</h3>
                <template v-if="isImage(currentDocument.fileMime)">
                    <AppImagePreview :src="currentDocument.fileUrl" :alt="currentDocument.fileName" size="lg" />
                    <div class="flex justify-end items-center gap-4 mt-2">
                        <button
                            v-if="can('ged.documents.edit')"
                            type="button"
                            class="flex items-center gap-1.5 text-sm text-accent hover:underline"
                            v-on:click="cropTarget = currentDocument"
                        >
                            <Crop class="w-4 h-4" :stroke-width="2" /> {{ t("suite.ged.documents.crop") }}
                        </button>
                        <a :href="currentDocument.fileUrl" download class="flex items-center gap-1.5 text-sm text-accent hover:underline">
                            <Download class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.download") }}
                        </a>
                    </div>
                </template>
                <template v-else-if="isPdf(currentDocument.fileMime)">
                    <iframe
                        :src="currentDocument.fileUrl"
                        class="w-full h-96 rounded-lg border border-line"
                        :title="currentDocument.fileName"
                    />
                    <div class="flex justify-end mt-2">
                        <a :href="currentDocument.fileUrl" download class="flex items-center gap-1.5 text-sm text-accent hover:underline">
                            <Download class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.download") }}
                        </a>
                    </div>
                </template>
                <div v-else class="flex items-center gap-3 p-3 bg-surface-2 rounded-lg border border-line">
                    <FileText class="w-8 h-8 text-muted shrink-0" :stroke-width="1.5" />
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-primary truncate">{{ currentDocument.fileName }}</p>
                        <p v-if="currentDocument.fileSize" class="text-xs text-muted">{{ Math.round(currentDocument.fileSize / 1024) }} ko</p>
                    </div>
                    <a
                        :href="currentDocument.fileUrl"
                        target="_blank"
                        class="flex items-center gap-1.5 text-sm text-accent hover:underline shrink-0"
                        download
                    >
                        <Download class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.download") }}
                    </a>
                </div>
            </section>

            <section class="aurora-card min-w-0 space-y-4 p-4 sm:p-5 lg:sticky lg:top-[calc(var(--aurora-topbar)+1rem)]" data-document-details>
                <h3 class="m-0 text-[0.9375rem] font-semibold text-primary">{{ t("suite.ged.documents.details_title") }}</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div v-if="currentDocument.categoryName">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-secondary">{{ t("suite.ged.documents.category") }}</p>
                        <p class="text-sm text-primary">{{ currentDocument.categoryName }}</p>
                    </div>
                    <div v-if="currentDocument.folderName">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-secondary">{{ t("suite.ged.documents.folder") }}</p>
                        <p class="text-sm text-primary flex items-center gap-1.5">
                            <Folder class="w-3.5 h-3.5 text-muted" :stroke-width="2" /> {{ currentDocument.folderName }}
                        </p>
                    </div>
                    <div v-if="currentDocument.tags?.length">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-secondary">{{ t("suite.ged.documents.tags") }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <DocumentTagChip v-for="tag in currentDocument.tags" :key="tag.id" :tag="tag" />
                        </div>
                    </div>
                    <div v-if="currentDocument.width && currentDocument.height">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-secondary">{{ t("suite.ged.documents.dimensions") }}</p>
                        <p class="text-sm text-primary">{{ currentDocument.width }} × {{ currentDocument.height }} px</p>
                    </div>
                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-secondary">{{ t("shared.common.dates") }}</p>
                        <p class="text-xs text-secondary">{{ t("shared.common.created") }} {{ formatDate(currentDocument.createdAt) }}</p>
                        <p class="text-xs text-secondary">{{ t("shared.common.updated") }} {{ formatDate(currentDocument.updatedAt) }}</p>
                    </div>
                </div>
            </section>
        </div>

        <!-- Edit modal -->
        <DocumentFamilyStrip
            class="border-t border-line/40 pt-4"
            :doc="currentDocument"
            :alternates-path="alternatesPath"
            v-on:open="openMember"
        />

        <AppModal
            :show="showEdit"
            :title="t('suite.ged.documents.edit', { title: editingDocument?.title ?? '' })"
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <div class="space-y-4">
                <AppInput
                    v-model="editForm.title"
                    :label="t('suite.ged.documents.title')"
                    :placeholder="t('shared.placeholders.title')"
                    :error="editErrors.title"
                    required
                />
                <AppInput
                    v-model="editForm.description"
                    :label="t('suite.ged.documents.description')"
                    :placeholder="t('shared.placeholders.description')"
                />
                <AppMultiselect
                    v-if="categories.length"
                    v-model="editForm.categoryId"
                    :label="t('suite.ged.documents.category')"
                    :options="categoryOptions"
                    :allow-empty="true"
                    :placeholder="t('suite.ged.documents.no_category')"
                />
                <AppMultiselect
                    v-if="tags.length"
                    v-model="editForm.tagIds"
                    :label="t('suite.ged.documents.tags')"
                    :options="tagOptions"
                    :multiple="true"
                    :allow-empty="true"
                    :placeholder="t('suite.ged.documents.no_tags')"
                />
                <AppMultiselect
                    v-if="folders.length"
                    v-model="editForm.folderId"
                    :label="t('suite.ged.documents.folder')"
                    :options="folderOptions"
                    :allow-empty="true"
                    :placeholder="t('suite.ged.documents.no_folder')"
                    track-by="id"
                    option-label="displayLabel"
                />
                <AppMultiselect
                    v-model="editForm.status"
                    :label="t('suite.ged.documents.status')"
                    :options="statusOptions"
                    :allow-empty="false"
                    :searchable="false"
                />
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showEdit = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="editLoading" v-on:click="submitEdit"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Delete modal -->
        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">{{ t("suite.ged.documents.delete_confirm", { title: currentDocument.title }) }}</p>
            <p class="text-sm text-secondary">{{ t("suite.ged.documents.delete_warning") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Crop modal -->
        <ImageCropperModal
            :item="cropTarget"
            :src="cropTarget?.fileUrl"
            :alt="cropTarget?.alt ?? ''"
            :name="cropTarget?.fileName"
            :crop-path="cropPath"
            entity-key="document"
            v-on:close="cropTarget = null"
            v-on:cropped="onCropped"
        />
    </div>
</template>
