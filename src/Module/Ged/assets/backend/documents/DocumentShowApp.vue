<script setup>
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useDocumentsForm, DOCUMENT_STATUS_BADGE } from "./composables/useDocumentsForm.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { Pencil, Trash2, ArrowLeft, Download, FileText, Folder, Tag, Save, X, Paperclip, Crop } from "lucide-vue-next";
import AppImagePreview from "@/shared/components/display/AppImagePreview.vue";
import ImageCropperModal from "@/shared/components/overlay/ImageCropperModal.vue";
import DocumentTagChip from "@ged/backend/documents/components/DocumentTagChip.vue";

const { t } = useI18n();
const { can } = usePrivileges();
const { formatDate } = useDateFormat();

const props = defineProps({
    document: { type: Object, required: true },
    backPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    cropPath: { type: String, required: true },
    listPath: { type: String, required: true },
});

const doc = ref({ ...props.document });
const cropTarget = ref(null);

function onCropped(updatedDoc) {
    if (updatedDoc) doc.value = { ...updatedDoc };
}

function onSaved() {
    window.location.reload();
}

function onDeleted() {
    window.location.href = props.listPath;
}

const {
    statusOptions,
    showEdit, editingDoc, editForm, editErrors, editLoading, openEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
} = useDocumentsForm(
    '',
    props.updatePath,
    props.deletePath,
    onSaved,
);

function handleDelete() {
    confirmDelete(doc.value);
}

function openEditDoc() {
    openEdit(doc.value);
}

function isImage(mimeType) {
    return mimeType?.startsWith('image/');
}

function isPdf(mimeType) {
    return mimeType === 'application/pdf';
}
</script>

<template>
    <div class="space-y-6 max-w-3xl">
        <!-- Header actions -->
        <div class="flex items-center justify-between gap-4">
            <a :href="backPath" class="flex items-center gap-1.5 text-sm text-muted hover:text-primary transition">
                <ArrowLeft class="w-4 h-4" :stroke-width="2" /> {{ t("backend.ged.documents.back_to_list") }}
            </a>
            <div class="flex items-center gap-2">
                <AppButton v-if="can('ged.documents.edit')" variant="secondary" size="md" v-on:click="openEditDoc">
                    <Pencil class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.edit") }}
                </AppButton>
                <AppButton
                    v-if="doc.fileUrl"
                    variant="secondary"
                    size="md"
                    :href="doc.fileUrl"
                    download
                >
                    <Download class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.download") }}
                </AppButton>
                <AppButton v-if="can('ged.documents.delete')" variant="danger" size="md" v-on:click="handleDelete">
                    <Trash2 class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.delete") }}
                </AppButton>
            </div>
        </div>

        <!-- Main card -->
        <div class="bg-surface border border-line rounded-xl divide-y divide-line/40">
            <!-- Title + status -->
            <div class="px-6 py-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-semibold text-primary">{{ doc.title }}</h1>
                        <p v-if="doc.reference" class="text-xs text-muted font-mono mt-1">{{ doc.reference }}</p>
                    </div>
                    <AppBadge :color="DOCUMENT_STATUS_BADGE[doc.status]" class="shrink-0 mt-1">{{ doc.statusLabel }}</AppBadge>
                </div>
                <p v-if="doc.description" class="mt-3 text-sm text-secondary leading-relaxed">{{ doc.description }}</p>
            </div>

            <!-- Metadata grid -->
            <div class="px-6 py-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div v-if="doc.categoryName">
                    <p class="text-xs text-muted uppercase tracking-wide mb-1">{{ t("backend.ged.documents.category") }}</p>
                    <p class="text-sm text-primary">{{ doc.categoryName }}</p>
                </div>
                <div v-if="doc.folderName">
                    <p class="text-xs text-muted uppercase tracking-wide mb-1">{{ t("backend.ged.documents.folder") }}</p>
                    <p class="text-sm text-primary flex items-center gap-1.5">
                        <Folder class="w-3.5 h-3.5 text-muted" :stroke-width="2" /> {{ doc.folderName }}
                    </p>
                </div>
                <div v-if="doc.tags?.length">
                    <p class="text-xs text-muted uppercase tracking-wide mb-1">{{ t("backend.ged.documents.tags") }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <DocumentTagChip v-for="tag in doc.tags" :key="tag.id" :tag="tag" />
                    </div>
                </div>
                <div v-if="doc.width && doc.height">
                    <p class="text-xs text-muted uppercase tracking-wide mb-1">{{ t("backend.ged.documents.dimensions") }}</p>
                    <p class="text-sm text-primary">{{ doc.width }} × {{ doc.height }} px</p>
                </div>
                <div>
                    <p class="text-xs text-muted uppercase tracking-wide mb-1">{{ t("shared.common.dates") }}</p>
                    <p class="text-xs text-secondary">{{ t("shared.common.created") }} {{ formatDate(doc.createdAt) }}</p>
                    <p class="text-xs text-secondary">{{ t("shared.common.updated") }} {{ formatDate(doc.updatedAt) }}</p>
                </div>
            </div>

            <!-- File -->
            <div v-if="doc.fileUrl" class="px-6 py-4">
                <p class="text-xs text-muted uppercase tracking-wide mb-3">{{ t("backend.ged.documents.file") }}</p>
                <template v-if="isImage(doc.fileMime)">
                    <AppImagePreview :src="doc.fileUrl" :alt="doc.fileName" size="lg" />
                    <div class="flex justify-end items-center gap-4 mt-2">
                        <button
                            v-if="can('ged.documents.edit')"
                            type="button"
                            class="flex items-center gap-1.5 text-sm text-accent hover:underline"
                            v-on:click="cropTarget = doc"
                        >
                            <Crop class="w-4 h-4" :stroke-width="2" /> {{ t("backend.ged.documents.crop") }}
                        </button>
                        <a :href="doc.fileUrl" download class="flex items-center gap-1.5 text-sm text-accent hover:underline">
                            <Download class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.download") }}
                        </a>
                    </div>
                </template>
                <template v-else-if="isPdf(doc.fileMime)">
                    <iframe
                        :src="doc.fileUrl"
                        class="w-full h-96 rounded-lg border border-line"
                        :title="doc.fileName"
                    />
                    <div class="flex justify-end mt-2">
                        <a :href="doc.fileUrl" download class="flex items-center gap-1.5 text-sm text-accent hover:underline">
                            <Download class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.download") }}
                        </a>
                    </div>
                </template>
                <div v-else class="flex items-center gap-3 p-3 bg-surface-2 rounded-lg border border-line">
                    <FileText class="w-8 h-8 text-muted shrink-0" :stroke-width="1.5" />
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-primary truncate">{{ doc.fileName }}</p>
                        <p v-if="doc.fileSize" class="text-xs text-muted">{{ Math.round(doc.fileSize / 1024) }} ko</p>
                    </div>
                    <a
                        :href="doc.fileUrl"
                        target="_blank"
                        class="flex items-center gap-1.5 text-sm text-accent hover:underline shrink-0"
                        download
                    >
                        <Download class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.download") }}
                    </a>
                </div>
            </div>
        </div>

        <!-- Edit modal -->
        <AppModal
            :show="showEdit"
            :title="t('backend.ged.documents.edit', { title: editingDoc?.title ?? '' })"
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <div class="space-y-4">
                <AppInput
                    v-model="editForm.title"
                    :label="t('backend.ged.documents.title')"
                    :placeholder="t('shared.placeholders.title')"
                    :error="editErrors.title"
                    required
                />
                <AppInput
                    v-model="editForm.description"
                    :label="t('backend.ged.documents.description')"
                    :placeholder="t('shared.placeholders.description')"
                />
                <AppMultiselect
                    v-model="editForm.status"
                    :label="t('backend.ged.documents.status')"
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
            <p class="text-sm text-primary">{{ t("backend.ged.documents.delete_confirm", { title: doc.title }) }}</p>
            <p class="text-sm text-secondary">{{ t("backend.ged.documents.delete_warning") }}</p>
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
