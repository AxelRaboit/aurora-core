<script setup>
import { useI18n } from "vue-i18n";
import { useListPage } from "@/shared/composables/list/useListPage.js";
import { useQrCode } from "@/shared/composables/overlay/useQrCode.js";
import { useClipboard } from "@/shared/composables/useClipboard.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useDocumentRowActions } from "./composables/useDocumentRowActions.js";
import { useDocumentsForm, DOCUMENT_STATUS_BADGE } from "./composables/useDocumentsForm.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppQrCodeModal from "@/shared/components/overlay/AppQrCodeModal.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppFileInput from "@/shared/components/form/file/AppFileInput.vue";
import AppNavListItem from "@/shared/components/nav/AppNavListItem.vue";
import AppTextLinkButton from "@/shared/components/action/AppTextLinkButton.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";
import { useDocumentFilters } from "./composables/useDocumentFilters.js";
import { useDocumentDetail } from "./composables/useDocumentDetail.js";
import { useDocumentsDisplay, DOCUMENT_SORT_FIELDS } from "./composables/useDocumentsDisplay.js";
import { useDocumentNavigation } from "./composables/useDocumentNavigation.js";
import { useDocumentSidebarTree } from "./composables/useDocumentSidebarTree.js";
import { useDocumentSidebarFolders } from "./composables/useDocumentSidebarFolders.js";
import { useDocumentDragDrop } from "./composables/useDocumentDragDrop.js";
import { useDocumentBulkActions } from "./composables/useDocumentBulkActions.js";
import { useDocumentCrop } from "./composables/useDocumentCrop.js";
import { useMultiSelection } from "@/shared/composables/list/useMultiSelection.js";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { Plus, Eye, Pencil, Trash2, Save, FileText, Paperclip, Upload, X, Folder, Download, QrCode, LayoutGrid, List, SortAsc, SortDesc, CheckSquare, Square, Copy, Crop, ExternalLink, Home, Layers, Star, ChevronRight, ChevronDown, Move } from "lucide-vue-next";
import ImageCropperModal from "@/shared/components/overlay/ImageCropperModal.vue";
import AppImagePreview from "@/shared/components/display/AppImagePreview.vue";
import AppImage from "@/shared/components/display/AppImage.vue";
import AppThumbnail from "@/shared/components/display/AppThumbnail.vue";
import AppFilePreview from "@/shared/components/display/AppFilePreview.vue";
import AppOverlayIconButton from "@/shared/components/action/AppOverlayIconButton.vue";
import AppSelectionCheck from "@/shared/components/feedback/AppSelectionCheck.vue";
import DocumentTagChip from "@ged/backend/documents/components/DocumentTagChip.vue";

const { t } = useI18n();
const { can } = usePrivileges();
const { formatDate } = useDateFormat();
const { formatSize } = useFileSize();
const props = defineProps({
    documents: { type: Object, default: () => ({}) },
    categories: { type: Array, default: () => [] },
    tags: { type: Array, default: () => [] },
    folders: { type: Array, default: () => [] },
    search: { type: String, default: "" },
    showPath: { type: String, default: "" },
    versionsPath: { type: String, default: "" },
    usagePath: { type: String, default: "" },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    bulkDeletePath: { type: String, default: "" },
    listPath: { type: String, required: true },
    uploadPath: { type: String, required: true },
    cropPath: { type: String, default: "" },
    movePath: { type: String, default: "" },
    bulkMovePath: { type: String, default: "" },
    folderCreatePath: { type: String, default: "" },
    folderEditPath: { type: String, default: "" },
    folderDeletePath: { type: String, default: "" },
    folderMovePath: { type: String, default: "" },
});

const categoryOptions = props.categories.map((c) => ({ value: c.id, label: c.name }));
const tagOptions = props.tags.map((tag) => ({ value: tag.id, label: tag.name }));

const { viewingDoc, viewingDocVersions, viewingDocUsage, viewDoc, closeDetail } = useDocumentDetail(props.versionsPath, props.usagePath);

const { qrItem: qrDoc, openQr, closeQr } = useQrCode();
const { copy } = useClipboard();

function permalinkFor(doc) {
    return doc.permalink ?? (doc.fileUrl ? window.location.origin + doc.fileUrl : "");
}

const {
    filterCategoryId, filterTagId, filterStatus, filterMimeGroup,
    hasActiveFilter, extraParams: filterExtraParams, applyFilter, resetFilters,
    // The arrow defers the read: `reset` comes from useListPage, which needs
    // these refs to exist before it is called.
    // eslint-disable-next-line no-use-before-define
} = useDocumentFilters(() => reset());

const mimeGroupOptions = [
    { value: "image", label: t("backend.ged.documents.type_image") },
    { value: "video", label: t("backend.ged.documents.type_video") },
    { value: "pdf", label: t("backend.ged.documents.type_pdf") },
    { value: "other", label: t("backend.ged.documents.type_other") },
];

const { selectedIds, isSelecting, toggle: toggleSelect, clear: clearSelection } = useMultiSelection();

// ── Sidebar nav state (current folder, all-view, history) ────────────────────
// Defined before useListPage so its extraParams() can read the navigation refs.
const {
    folders, currentFolderId, allDocumentsView, rootOnly,
    navigateTo, navigateToRoot, navigateToAll,
    onListResponse, extraParams: navExtraParams,
    // eslint-disable-next-line no-use-before-define -- same cycle as above.
} = useDocumentNavigation(props, () => reset(), clearSelection);

// Sidebar (folderId / rootOnly) drives the folder filter — strip the legacy
// chip's folderId from the existing useDocumentFilters payload to avoid
// double-writing the same query param.
function combinedExtraParams() {
    const base = filterExtraParams();
    delete base.folderId;
    return { ...base, ...navExtraParams() };
}

const { items, loading, page, totalPages, search: searchInput, onSearch, goToPage, reload: reset } = useListPage(
    props.listPath,
    {
        initialSearch: props.search,
        initialData: props.documents,
        extraParams: combinedExtraParams,
        onData: onListResponse,
    },
);

const {
    statusOptions,
    showCreate, newDoc, uploadingCreate, createErrors, createLoading, openCreate, onLocalFileCreate, submitCreate,
    showEdit, editingDoc, editForm, uploadingEdit, editErrors, editLoading, openEdit, onLocalFileEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
} = useDocumentsForm(props.createPath, props.updatePath, props.deletePath, reset, props.uploadPath);

const { viewMode, setViewMode, sortBy, sortDir, setSort, displayedItems } = useDocumentsDisplay(items);

const {
    folderTree, flatFolders, allFlatFolders, currentFolder, breadcrumbs,
    collapsedFolderIds, toggleCollapse,
    favouriteFolderIds, toggleFavourite, favouriteFolders,
} = useDocumentSidebarTree(folders, currentFolderId);

const {
    folderModal, folderForm,
    openCreateFolder, openEditFolder, submitFolder,
    deletingFolder, confirmDeleteFolder,
    folderEditOptions, folderParentSelectOptions,
} = useDocumentSidebarFolders(
    props, folders, currentFolderId, flatFolders, allFlatFolders, navigateTo, reset,
);

// Two sets on this screen: what a document offers, and what a folder in the
// tree does. Both were written twice — once for the cards, once for the table —
// so the two copies could already disagree.
const documentActions = useDocumentRowActions({
    can,
    viewDoc,
    openQr,
    openEdit,
    confirmDelete,
});

const folderActions = useEditDeleteActions({
    can,
    editPermission: "ged.folders.manage",
    deletePermission: "ged.folders.manage",
    openEdit: openEditFolder,
    confirmDelete: (folder) => {
        deletingFolder.value = folder;
    },
    editDescription: "backend.ged.folders.row_actions.edit_description",
    deleteDescription: "backend.ged.folders.row_actions.delete_description",
});

const {
    dragOverFolderId, rootDragOver,
    onDocumentDragStart, onFolderDragStart, onFolderDragOver, onRootDragOver, onDragLeave, onFolderDrop,
} = useDocumentDragDrop(props, items, folders, currentFolderId, reset);

const { doBulkDelete, bulkMoveTargetId, openBulkMove, bulkMove } = useDocumentBulkActions(
    props, items, selectedIds, isSelecting, clearSelection, currentFolderId, reset,
);

const { cropTarget, onCropped } = useDocumentCrop(viewingDoc, reset);
</script>

<template>
    <div class="space-y-4">
        <!-- Header: breadcrumb + search + add -->
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 bg-surface border border-line/60 rounded-xl px-4 py-3">
            <nav class="flex items-center gap-1 text-sm text-muted min-w-0 flex-1 flex-wrap">
                <template v-if="allDocumentsView">
                    <span class="flex items-center gap-1.5 text-primary shrink-0">
                        <Layers class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.ged.documents.all_documents") }}
                    </span>
                </template>
                <template v-else>
                    <AppTextLinkButton color="muted" size="sm" class="shrink-0 no-underline hover:no-underline" v-on:click="navigateToRoot">
                        <Home class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.ged.documents.root_folder") }}
                    </AppTextLinkButton>
                    <template v-for="crumb in breadcrumbs" :key="crumb.id">
                        <ChevronRight class="w-3 h-3 shrink-0" :stroke-width="2" />
                        <AppTextLinkButton color="muted" size="sm" class="truncate no-underline hover:no-underline" v-on:click="navigateTo(crumb.id)">
                            {{ crumb.name }}
                        </AppTextLinkButton>
                    </template>
                </template>
            </nav>

            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:shrink-0">
                <div class="w-full sm:w-64">
                    <AppSearchInput v-model="searchInput" :placeholder="t('backend.ged.documents.search_placeholder')" v-on:search="onSearch" />
                </div>
                <AppButton
                    v-if="can('ged.documents.create')"
                    variant="primary"
                    size="md"
                    class="w-full sm:w-auto"
                    v-on:click="openCreate"
                >
                    <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.ged.documents.add") }}
                </AppButton>
            </div>
        </div>

        <div class="flex flex-col lg:flex-row gap-4">
            <!-- Sidebar -->
            <aside class="lg:w-72 shrink-0 space-y-2">
                <div v-if="favouriteFolders.length" class="space-y-0.5">
                    <h3 class="text-xs font-semibold text-secondary uppercase tracking-wide flex items-center gap-1.5 px-1">
                        <Star class="w-3 h-3 text-amber-400" :stroke-width="2" fill="currentColor" />
                        {{ t("backend.ged.documents.favourites") }}
                    </h3>
                    <AppNavListItem
                        v-for="fav in favouriteFolders"
                        :key="fav.id"
                        :active="currentFolderId === fav.id"
                        v-on:click="navigateTo(fav.id)"
                    >
                        <template #icon>
                            <Folder class="w-3.5 h-3.5" :stroke-width="2" />
                        </template>
                        {{ fav.name }}
                    </AppNavListItem>
                    <div class="border-t border-line/40 my-1" />
                </div>

                <div class="flex items-center gap-1.5">
                    <h2 class="text-sm font-semibold text-secondary uppercase tracking-wide flex-1">{{ t("backend.ged.documents.folders_section") }}</h2>
                    <AppIconButton
                        v-if="can('ged.folders.manage')"
                        :title="t('backend.ged.documents.new_folder')"
                        v-on:click="openCreateFolder"
                    >
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <div class="space-y-0.5">
                    <AppNavListItem
                        :active="allDocumentsView"
                        v-on:click="navigateToAll()"
                    >
                        <template #icon>
                            <Layers class="w-4 h-4" :stroke-width="2" />
                        </template>
                        {{ t("backend.ged.documents.all_documents") }}
                    </AppNavListItem>
                    <AppNavListItem
                        :active="rootOnly && !currentFolderId"
                        :drag-over="rootDragOver"
                        v-on:click="navigateToRoot"
                        v-on:dragover="onRootDragOver"
                        v-on:dragleave="onDragLeave"
                        v-on:drop="onFolderDrop($event, null)"
                    >
                        <template #icon>
                            <Home class="w-4 h-4" :stroke-width="2" />
                        </template>
                        {{ t("backend.ged.documents.root_folder") }}
                    </AppNavListItem>
                    <div
                        v-for="folder in flatFolders"
                        :key="folder.id"
                        class="group flex items-center gap-1"
                        :style="{ paddingLeft: `${folder.depth * 1}rem` }"
                        draggable="true"
                        v-on:dragstart="onFolderDragStart($event, folder)"
                    >
                        <AppIconButton
                            v-if="folder.childCount > 0"
                            size="sm"
                            variant="ghost"
                            class="-ml-1 shrink-0"
                            :title="collapsedFolderIds.has(folder.id) ? t('backend.ged.documents.expand') : t('backend.ged.documents.collapse')"
                            v-on:click.stop="toggleCollapse(folder.id)"
                        >
                            <ChevronRight v-if="collapsedFolderIds.has(folder.id)" class="w-3 h-3" :stroke-width="2" />
                            <ChevronDown v-else class="w-3 h-3" :stroke-width="2" />
                        </AppIconButton>
                        <span v-else class="w-4 shrink-0" />
                        <AppNavListItem
                            class="flex-1 min-w-0"
                            :active="currentFolderId === folder.id"
                            :drag-over="dragOverFolderId === folder.id"
                            v-on:click="navigateTo(folder.id)"
                            v-on:dragover="onFolderDragOver($event, folder.id)"
                            v-on:dragleave="onDragLeave"
                            v-on:drop="onFolderDrop($event, folder.id)"
                        >
                            <template #icon>
                                <Folder class="w-4 h-4" :stroke-width="2" />
                            </template>
                            {{ folder.name }}
                            <template v-if="folder.documentCount > 0" #trailing>
                                <span class="text-xs text-muted font-mono">{{ folder.documentCount }}</span>
                            </template>
                        </AppNavListItem>
                        <div class="opacity-100 lg:opacity-0 lg:group-hover:opacity-100 flex gap-0.5 transition-opacity">
                            <AppIconButton
                                size="sm"
                                variant="ghost"
                                :class="favouriteFolderIds.has(folder.id) ? 'text-amber-400' : 'text-muted hover:text-amber-400'"
                                :title="favouriteFolderIds.has(folder.id) ? t('backend.ged.documents.unfavourite') : t('backend.ged.documents.favourite')"
                                v-on:click.stop="toggleFavourite(folder.id)"
                            >
                                <Star class="w-3.5 h-3.5" :stroke-width="2" :fill="favouriteFolderIds.has(folder.id) ? 'currentColor' : 'none'" />
                            </AppIconButton>
                            <AppRowActions :actions="folderActions(folder)" :label="folder.name ?? ''" />
                        </div>
                    </div>
                </div>
            </aside>

            <main class="flex-1 min-w-0 space-y-4">
                <!-- Filters -->
                <div v-if="categories.length || tags.length" class="flex flex-col sm:flex-row sm:flex-wrap gap-2">
                    <AppMultiselect
                        v-if="categories.length"
                        v-model="filterCategoryId"
                        :options="categoryOptions"
                        :allow-empty="true"
                        :placeholder="t('backend.ged.documents.filter_by_category')"
                        class="w-full sm:w-auto sm:min-w-44"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppMultiselect
                        v-if="tags.length"
                        v-model="filterTagId"
                        :options="tagOptions"
                        :allow-empty="true"
                        :placeholder="t('backend.ged.documents.filter_by_tag')"
                        class="w-full sm:w-auto sm:min-w-44"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppMultiselect
                        v-model="filterStatus"
                        :options="statusOptions"
                        :allow-empty="true"
                        :searchable="false"
                        :placeholder="t('backend.ged.documents.filter_by_status')"
                        class="w-full sm:w-auto sm:min-w-44"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppMultiselect
                        v-model="filterMimeGroup"
                        :options="mimeGroupOptions"
                        :allow-empty="true"
                        :searchable="false"
                        :placeholder="t('backend.ged.documents.filter_by_type')"
                        class="w-full sm:w-auto sm:min-w-44"
                        v-on:update:model-value="applyFilter"
                    />
                    <AppButton
                        v-if="hasActiveFilter"
                        variant="ghost"
                        size="sm"
                        class="w-full sm:w-auto"
                        v-on:click="resetFilters"
                    >
                        <X class="w-3 h-3" :stroke-width="2" /> {{ t("shared.common.reset") }}
                    </AppButton>
                </div>

                <!-- Selection bar -->
                <div v-if="selectedIds.size" class="flex flex-wrap items-center gap-2 bg-accent-500/10 border border-accent-400/30 rounded-xl px-4 py-2.5">
                    <span class="text-sm font-medium text-accent-400">{{ selectedIds.size }} {{ t("shared.common.selected") }}</span>
                    <div class="flex gap-2 ml-auto flex-wrap">
                        <AppButton size="sm" variant="ghost" v-on:click="() => { bulkMoveTargetId = null; openBulkMove = true; }">
                            <Move class="w-3.5 h-3.5" :stroke-width="2" />
                            {{ t("backend.ged.documents.move") }}
                        </AppButton>
                        <AppButton v-if="can('ged.documents.delete')" size="sm" variant="danger" v-on:click="doBulkDelete">
                            <Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                        </AppButton>
                        <AppButton size="sm" variant="ghost" v-on:click="clearSelection">
                            <X class="w-3.5 h-3.5" :stroke-width="2" />
                        </AppButton>
                    </div>
                </div>

                <!-- View toolbar: sort + view mode + multiselect -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <div class="flex gap-1 border border-line/60 rounded-lg p-0.5">
                        <AppTab
                            v-for="s in DOCUMENT_SORT_FIELDS"
                            :key="s.key"
                            size="xs"
                            :active="sortBy === s.key"
                            :title="s.labelKey ? t(s.labelKey) : s.label"
                            v-on:click="setSort(s.key)"
                        >
                            {{ s.labelKey ? t(s.labelKey) : s.label }}
                            <SortAsc v-if="sortBy === s.key && sortDir === 'asc'" class="w-3 h-3" :stroke-width="2" />
                            <SortDesc v-else-if="sortBy === s.key" class="w-3 h-3" :stroke-width="2" />
                        </AppTab>
                    </div>
                    <div class="flex border border-line/60 rounded-lg p-0.5">
                        <AppIconButton
                            size="sm"
                            variant="ghost"
                            :class="viewMode === 'grid' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                            v-on:click="setViewMode('grid')"
                        >
                            <LayoutGrid class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            size="sm"
                            variant="ghost"
                            :class="viewMode === 'list' ? 'bg-surface-3 text-primary' : 'text-muted hover:text-primary'"
                            v-on:click="setViewMode('list')"
                        >
                            <List class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                    <AppIconButton
                        v-if="can('ged.documents.delete') || can('ged.documents.edit')"
                        size="sm"
                        variant="ghost"
                        class="border border-line/60"
                        :class="isSelecting ? 'bg-accent-500/15 text-accent-400' : 'text-muted hover:text-primary'"
                        v-on:click="isSelecting = !isSelecting; if (!isSelecting) clearSelection()"
                    >
                        <CheckSquare class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <div class="relative space-y-4">
                    <AppNoData v-if="!displayedItems?.length" :message="t('backend.ged.documents.empty')" />

                    <!-- Grid view -->
                    <div v-if="viewMode === 'grid' && displayedItems?.length" class="grid grid-cols-1 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
                        <div
                            v-for="doc in displayedItems"
                            :key="doc.id"
                            class="group relative bg-surface border rounded-lg overflow-hidden transition-colors cursor-pointer"
                            :class="[
                                selectedIds.has(doc.id) ? 'border-accent-400 ring-2 ring-accent-500' : 'border-line/60 hover:border-accent-400',
                            ]"
                            draggable="true"
                            v-on:click="isSelecting ? toggleSelect(doc.id) : viewDoc(doc)"
                            v-on:dragstart="onDocumentDragStart($event, doc)"
                        >
                            <div v-if="isSelecting" class="absolute top-1.5 left-1.5 z-10" v-on:click.stop="toggleSelect(doc.id)">
                                <AppSelectionCheck :active="selectedIds.has(doc.id)" />
                            </div>
                            <div class="relative aspect-square bg-surface-2 flex items-center justify-center overflow-hidden">
                                <AppImage
                                    v-if="doc.thumbnailUrl"
                                    :src="doc.thumbnailUrl"
                                    :alt="doc.fileName ?? doc.title"
                                    object-fit="cover"
                                />
                                <FileText v-else-if="doc.fileMime === 'application/pdf'" class="w-12 h-12 text-rose-400" :stroke-width="1.5" />
                                <Paperclip v-else-if="doc.fileUrl" class="w-10 h-10 text-muted" :stroke-width="1.5" />
                                <FileText v-else class="w-10 h-10 text-muted" :stroke-width="1.5" />
                                <AppBadge v-if="doc.status" :color="DOCUMENT_STATUS_BADGE[doc.status]" class="absolute top-1 right-1">{{ doc.statusLabel }}</AppBadge>
                                <div v-if="!isSelecting" class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1.5">
                                    <AppOverlayIconButton size="sm" variant="light" :title="t('shared.common.view')" v-on:click.stop="viewDoc(doc)">
                                        <Eye class="w-4 h-4" :stroke-width="2" />
                                    </AppOverlayIconButton>
                                    <AppOverlayIconButton
                                        v-if="can('ged.documents.edit')"
                                        size="sm"
                                        variant="light"
                                        :title="t('shared.common.edit')"
                                        v-on:click.stop="openEdit(doc)"
                                    >
                                        <Pencil class="w-4 h-4" :stroke-width="2" />
                                    </AppOverlayIconButton>
                                    <AppOverlayIconButton
                                        v-if="doc.fileUrl"
                                        size="sm"
                                        variant="light"
                                        :title="t('shared.common.qr_code')"
                                        v-on:click.stop="openQr(doc)"
                                    >
                                        <QrCode class="w-4 h-4" :stroke-width="2" />
                                    </AppOverlayIconButton>
                                </div>
                            </div>
                            <div class="p-2 space-y-1">
                                <div class="text-xs font-medium text-primary truncate" :title="doc.title">{{ doc.title }}</div>
                                <div v-if="doc.reference" class="text-xs text-muted font-mono truncate">{{ doc.reference }}</div>
                                <div class="text-xs text-muted">
                                    <span v-if="doc.fileSize">{{ formatSize(doc.fileSize) }}</span>
                                    <span v-if="doc.width && doc.height"><span v-if="doc.fileSize"> · </span>{{ doc.width }}×{{ doc.height }}</span>
                                    <span v-if="doc.categoryName"><span v-if="doc.fileSize || doc.width"> · </span>{{ doc.categoryName }}</span>
                                </div>
                                <div v-if="doc.folderName" class="text-xs text-accent-400/80 truncate flex items-center gap-1">
                                    <Folder class="w-2.5 h-2.5 shrink-0" :stroke-width="2" />{{ doc.folderName }}
                                </div>
                                <div v-if="doc.tags?.length" class="flex flex-wrap gap-1 pt-0.5">
                                    <DocumentTagChip v-for="tag in doc.tags" :key="tag.id" :tag="tag" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile cards (list view fallback on mobile) -->
                    <div v-else-if="viewMode === 'list' && displayedItems?.length" class="sm:hidden space-y-2">
                        <div
                            v-for="doc in displayedItems"
                            :key="doc.id"
                            class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm relative"
                            :class="{ 'ring-2 ring-accent-400': isSelecting && selectedIds.has(doc.id), 'cursor-pointer': isSelecting }"
                            draggable="true"
                            v-on:click="isSelecting ? toggleSelect(doc.id) : null"
                            v-on:dragstart="onDocumentDragStart($event, doc)"
                        >
                            <div v-if="isSelecting" class="absolute top-1.5 left-1.5 z-10 bg-surface/90 rounded p-0.5" v-on:click.stop="toggleSelect(doc.id)">
                                <CheckSquare v-if="selectedIds.has(doc.id)" class="w-4 h-4 text-accent-400" :stroke-width="2" />
                                <Square v-else class="w-4 h-4 text-muted" :stroke-width="2" />
                            </div>
                            <div class="flex items-start gap-3 p-4">
                                <div class="shrink-0 mt-0.5">
                                    <AppThumbnail
                                        v-if="doc.thumbnailUrl"
                                        :src="doc.thumbnailUrl"
                                        :alt="doc.fileName"
                                        size="sm"
                                    />
                                    <div v-else-if="doc.fileMime === 'application/pdf'" class="w-8 h-8 flex items-center justify-center rounded border border-line/60 bg-surface-2">
                                        <FileText class="w-4 h-4 text-rose-400" :stroke-width="1.5" />
                                    </div>
                                    <div v-else-if="doc.fileUrl" class="w-8 h-8 flex items-center justify-center rounded border border-line/60 bg-surface-2">
                                        <Paperclip class="w-4 h-4 text-muted" :stroke-width="1.5" />
                                    </div>
                                    <div v-else class="w-8 h-8 flex items-center justify-center rounded border border-line/60 bg-surface-2">
                                        <FileText class="w-4 h-4 text-muted" :stroke-width="1.5" />
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-primary text-sm truncate">{{ doc.title }}</p>
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 mt-0.5">
                                        <span v-if="doc.reference" class="text-xs text-muted font-mono">{{ doc.reference }}</span>
                                        <span v-if="doc.folderName" class="text-xs text-muted flex items-center gap-0.5">
                                            <Folder class="w-3 h-3" :stroke-width="2" /> {{ doc.folderName }}
                                        </span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                                        <AppBadge :color="DOCUMENT_STATUS_BADGE[doc.status]">{{ doc.statusLabel }}</AppBadge>
                                        <span v-if="doc.categoryName" class="text-xs text-muted">{{ doc.categoryName }}</span>
                                        <span v-if="doc.fileSize" class="text-xs text-muted tabular-nums">{{ formatSize(doc.fileSize) }}</span>
                                    </div>
                                    <div v-if="doc.tags?.length" class="flex flex-wrap gap-1 mt-1.5">
                                        <DocumentTagChip v-for="tag in doc.tags" :key="tag.id" :tag="tag" />
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-end px-3 py-2 border-t border-line/40 bg-surface-2/40">
                                <AppRowActions :actions="documentActions(doc)" :label="doc.title ?? ''" />
                            </div>
                        </div>
                    </div>

                    <!-- Desktop table (list view) -->
                    <div v-show="viewMode === 'list'" class="hidden sm:block bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-surface-2/50 border-b border-line/40">
                                    <th v-if="isSelecting" class="w-8 px-3 py-3" />
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.ged.documents.title") }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("backend.ged.documents.category") }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("backend.ged.documents.status") }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("backend.ged.documents.file") }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t("backend.ged.documents.size") }}</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden xl:table-cell">{{ t("backend.ged.documents.preview") }}</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t("shared.common.actions") }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line/40">
                                <tr
                                    v-for="doc in displayedItems"
                                    :key="doc.id"
                                    class="group hover:bg-surface-2/40 transition-colors"
                                    :class="{ 'bg-accent-500/10': isSelecting && selectedIds.has(doc.id), 'cursor-pointer': isSelecting }"
                                    draggable="true"
                                    v-on:click="isSelecting ? toggleSelect(doc.id) : null"
                                    v-on:dragstart="onDocumentDragStart($event, doc)"
                                >
                                    <td v-if="isSelecting" class="px-3 py-3" v-on:click.stop="toggleSelect(doc.id)">
                                        <CheckSquare v-if="selectedIds.has(doc.id)" class="w-4 h-4 text-accent-400" :stroke-width="2" />
                                        <Square v-else class="w-4 h-4 text-muted" :stroke-width="2" />
                                    </td>
                                    <td class="px-6 py-3">
                                        <p class="font-medium text-primary">{{ doc.title }}</p>
                                        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                            <span v-if="doc.reference" class="text-xs text-muted font-mono">{{ doc.reference }}</span>
                                            <span v-if="doc.folderName" class="text-xs text-muted flex items-center gap-0.5">
                                                <Folder class="w-3 h-3" :stroke-width="2" /> {{ doc.folderName }}
                                            </span>
                                            <DocumentTagChip v-for="tag in doc.tags" :key="tag.id" :tag="tag" />
                                        </div>
                                    </td>
                                    <td class="px-6 py-3 text-secondary hidden md:table-cell">{{ doc.categoryName ?? t("backend.ged.documents.no_category") }}</td>
                                    <td class="px-6 py-3 hidden lg:table-cell">
                                        <AppBadge :color="DOCUMENT_STATUS_BADGE[doc.status]">{{ doc.statusLabel }}</AppBadge>
                                    </td>
                                    <td class="px-6 py-3 hidden lg:table-cell">
                                        <span v-if="doc.fileName" class="flex items-center gap-1 text-xs text-muted"><Paperclip class="w-3 h-3" :stroke-width="2" /> {{ doc.fileName }}</span>
                                        <span v-else class="text-muted text-xs">—</span>
                                    </td>
                                    <td class="px-6 py-3 text-right hidden lg:table-cell text-xs text-muted tabular-nums">
                                        <span v-if="doc.fileSize">{{ formatSize(doc.fileSize) }}</span>
                                        <span v-else>—</span>
                                    </td>
                                    <td class="px-6 py-3 hidden xl:table-cell">
                                        <AppThumbnail
                                            v-if="doc.thumbnailUrl"
                                            :src="doc.thumbnailUrl"
                                            :alt="doc.fileName"
                                            size="landscape"
                                        />
                                        <div v-else-if="doc.fileMime === 'application/pdf'" class="flex items-center gap-1.5 text-xs text-muted">
                                            <FileText class="w-5 h-5 shrink-0 text-rose-400" :stroke-width="1.5" /> PDF
                                        </div>
                                        <div v-else-if="doc.fileUrl" class="flex items-center gap-1.5 text-xs text-muted">
                                            <FileText class="w-5 h-5 shrink-0" :stroke-width="1.5" /> {{ doc.fileMime ?? '—' }}
                                        </div>
                                        <span v-else class="text-muted text-xs">—</span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="flex items-center justify-end gap-0.5">
                                            <AppRowActions :actions="documentActions(doc)" :label="doc.title ?? ''" />
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!items?.length">
                                    <td :colspan="6"><AppNoData :message="t('backend.ged.documents.empty')" /></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <AppPagination v-if="totalPages > 1" :page="page" :total-pages="totalPages" v-on:go-to-page="goToPage" />
                    <AppLoader :active="loading" />
                </div>
            </main>
        </div>

        <!-- Create modal -->
        <AppModal
            :show="showCreate"
            :title="t('backend.ged.documents.create')"
            :icon="FileText"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <div class="space-y-4">
                <AppInput
                    v-model="newDoc.title"
                    :label="t('backend.ged.documents.title')"
                    :placeholder="t('backend.ged.documents.title_placeholder')"
                    :error="createErrors.title"
                    required
                />
                <AppInput v-model="newDoc.description" :label="t('backend.ged.documents.description')" :placeholder="t('backend.ged.documents.description_placeholder')" />
                <template v-if="newDoc.mimeType?.startsWith('image/')">
                    <AppInput v-model="newDoc.alt" :label="t('backend.ged.documents.alt')" :placeholder="t('backend.ged.documents.alt_placeholder')" />
                    <AppInput v-model="newDoc.caption" :label="t('backend.ged.documents.caption')" :placeholder="t('backend.ged.documents.caption_placeholder')" />
                </template>
                <AppMultiselect
                    v-model="newDoc.categoryId"
                    :label="t('backend.ged.documents.category')"
                    :options="categoryOptions"
                    :allow-empty="true"
                    :placeholder="t('backend.ged.documents.no_category')"
                />
                <AppMultiselect
                    v-if="tags.length"
                    v-model="newDoc.tagIds"
                    :label="t('backend.ged.documents.tags')"
                    :options="tagOptions"
                    :multiple="true"
                    :allow-empty="true"
                    :placeholder="t('backend.ged.documents.no_tags')"
                />
                <AppMultiselect
                    v-if="folders.length"
                    v-model="newDoc.folderId"
                    :label="t('backend.ged.documents.folder')"
                    :options="folderEditOptions"
                    :allow-empty="true"
                    :placeholder="t('backend.ged.documents.no_folder')"
                    track-by="id"
                    option-label="displayLabel"
                />
                <AppMultiselect
                    v-model="newDoc.status"
                    :label="t('backend.ged.documents.status')"
                    :options="statusOptions"
                    :allow-empty="false"
                    :searchable="false"
                />
                <div class="flex items-center gap-2 flex-wrap">
                    <AppFileInput v-on:change="onLocalFileCreate">
                        <template #default="{ trigger }">
                            <AppButton
                                variant="ghost"
                                size="sm"
                                type="button"
                                :loading="uploadingCreate"
                                v-on:click="trigger"
                            >
                                <Upload class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.ged.documents.choose_file") }}
                            </AppButton>
                        </template>
                    </AppFileInput>
                    <span v-if="newDoc.originalName ?? newDoc.fileName" class="text-sm text-muted flex items-center gap-1"><FileText class="w-4 h-4" :stroke-width="2" /> {{ newDoc.originalName ?? newDoc.fileName }}</span>
                </div>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCreate = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="createLoading" v-on:click="submitCreate"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Edit modal -->
        <AppModal
            :show="showEdit"
            :title="t('backend.ged.documents.edit', { title: editingDoc?.title ?? '' })"
            :icon="Pencil"
            max-width="4xl"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <div :class="editingDoc?.fileUrl ? 'grid grid-cols-1 md:grid-cols-2 gap-5 items-start' : 'space-y-4'">
                <AppFilePreview
                    v-if="editingDoc?.fileUrl"
                    :url="editingDoc.fileUrl"
                    :mime="editingDoc.fileMime"
                    :name="editingDoc.fileName"
                    :alt="editingDoc.alt ?? editingDoc.title"
                    max-height="28rem"
                    class="md:sticky md:top-0"
                />

                <div class="space-y-4">
                    <AppInput
                        v-model="editForm.title"
                        :label="t('backend.ged.documents.title')"
                        :placeholder="t('backend.ged.documents.title_placeholder')"
                        :error="editErrors.title"
                        required
                    />
                    <AppInput v-model="editForm.description" :label="t('backend.ged.documents.description')" :placeholder="t('backend.ged.documents.description_placeholder')" />
                    <template v-if="editForm.mimeType?.startsWith('image/')">
                        <AppInput v-model="editForm.alt" :label="t('backend.ged.documents.alt')" :placeholder="t('backend.ged.documents.alt_placeholder')" />
                        <AppInput v-model="editForm.caption" :label="t('backend.ged.documents.caption')" :placeholder="t('backend.ged.documents.caption_placeholder')" />
                    </template>
                    <AppMultiselect
                        v-model="editForm.categoryId"
                        :label="t('backend.ged.documents.category')"
                        :options="categoryOptions"
                        :allow-empty="true"
                        :placeholder="t('backend.ged.documents.no_category')"
                    />
                    <AppMultiselect
                        v-if="tags.length"
                        v-model="editForm.tagIds"
                        :label="t('backend.ged.documents.tags')"
                        :options="tagOptions"
                        :multiple="true"
                        :allow-empty="true"
                        :placeholder="t('backend.ged.documents.no_tags')"
                    />
                    <AppMultiselect
                        v-if="folders.length"
                        v-model="editForm.folderId"
                        :label="t('backend.ged.documents.folder')"
                        :options="folderEditOptions"
                        :allow-empty="true"
                        :placeholder="t('backend.ged.documents.no_folder')"
                        track-by="id"
                        option-label="displayLabel"
                    />
                    <AppMultiselect
                        v-model="editForm.status"
                        :label="t('backend.ged.documents.status')"
                        :options="statusOptions"
                        :allow-empty="false"
                        :searchable="false"
                    />
                    <div class="flex items-center gap-2 flex-wrap">
                        <AppFileInput v-on:change="onLocalFileEdit">
                            <template #default="{ trigger }">
                                <AppButton
                                    variant="ghost"
                                    size="sm"
                                    type="button"
                                    :loading="uploadingEdit"
                                    v-on:click="trigger"
                                >
                                    <Upload class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.ged.documents.choose_file") }}
                                </AppButton>
                            </template>
                        </AppFileInput>
                        <span v-if="editForm.fileName" class="text-sm text-muted flex items-center gap-1"><FileText class="w-4 h-4" :stroke-width="2" /> {{ editForm.fileName }}</span>
                    </div>
                </div>
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showEdit = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="editLoading" v-on:click="submitEdit"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Delete document modal -->
        <AppModal
            :show="!!pendingDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingDelete = null"
        >
            <p class="text-sm text-primary">{{ t("backend.ged.documents.delete_confirm", { title: pendingDelete?.title ?? "" }) }}</p>
            <p class="text-sm text-secondary">{{ t("backend.ged.documents.delete_warning") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Folder modal (sidebar create/edit) -->
        <AppModal
            :show="folderModal.open"
            max-width="md"
            :title="folderModal.editing ? t('backend.ged.documents.edit_folder') : t('backend.ged.documents.new_folder')"
            :icon="folderModal.editing ? Pencil : Folder"
            :closeable="false"
            v-on:close="folderModal.open = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitFolder">
                <AppInput
                    v-model="folderForm.name"
                    :label="t('backend.ged.documents.folder_name')"
                    :placeholder="t('backend.ged.documents.folder_name_placeholder')"
                    :error="folderModal.errors.name ?? ''"
                    required
                />
                <AppMultiselect
                    v-model="folderForm.parentId"
                    :options="folderParentSelectOptions"
                    :label="t('backend.ged.documents.parent_folder')"
                    :placeholder="t('backend.ged.documents.root_folder')"
                    :allow-empty="true"
                    track-by="id"
                    option-label="displayLabel"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="folderModal.open = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="folderModal.saving" v-on:click="submitFolder"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Delete folder modal -->
        <AppModal
            :show="!!deletingFolder"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="deletingFolder = null"
        >
            <p class="text-sm text-primary">{{ t("backend.ged.documents.delete_folder_confirm", { name: deletingFolder?.name }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="deletingFolder = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" v-on:click="confirmDeleteFolder"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Bulk move modal -->
        <AppModal :show="openBulkMove" max-width="sm" v-on:close="openBulkMove = false">
            <h3 class="text-sm font-semibold text-primary mb-3">{{ t("backend.ged.documents.bulk_move", { count: selectedIds.size }) }}</h3>
            <AppMultiselect
                v-model="bulkMoveTargetId"
                :options="[{ id: null, displayLabel: t('backend.ged.documents.root_folder') }, ...allFlatFolders.map(f => ({ id: f.id, displayLabel: '  '.repeat(f.depth) + f.name }))]"
                :label="t('backend.ged.documents.folder')"
                :placeholder="t('backend.ged.documents.root_folder')"
                :allow-empty="true"
                track-by="id"
                option-label="displayLabel"
            />
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="openBulkMove = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" v-on:click="bulkMove"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <!-- Detail modal -->
        <AppModal
            :show="!!viewingDoc"
            :title="viewingDoc?.title ?? ''"
            :icon="FileText"
            max-width="5xl"
            :closeable="false"
            v-on:close="viewingDoc = null"
        >
            <template v-if="viewingDoc">
                <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 items-start">
                    <div v-if="viewingDoc.fileUrl" class="lg:col-span-3 rounded-lg border border-line overflow-hidden">
                        <AppImagePreview v-if="viewingDoc.fileMime?.startsWith('image/')" :src="viewingDoc.fileUrl" :alt="viewingDoc.alt ?? viewingDoc.fileName" full />
                        <iframe
                            v-else-if="viewingDoc.fileMime === 'application/pdf'"
                            :src="viewingDoc.fileUrl"
                            class="w-full h-144"
                            :title="viewingDoc.fileName"
                        />
                        <div v-else class="flex flex-col items-center justify-center gap-3 px-4 py-16 bg-surface-2">
                            <FileText class="w-16 h-16 text-muted" :stroke-width="1.25" />
                            <p class="text-sm font-medium text-primary truncate max-w-full">{{ viewingDoc.fileName }}</p>
                            <p v-if="viewingDoc.fileMime" class="text-xs text-muted">{{ viewingDoc.fileMime }}</p>
                        </div>
                    </div>

                    <div :class="viewingDoc.fileUrl ? 'lg:col-span-2 space-y-4' : 'lg:col-span-5 space-y-4'">
                        <div class="flex items-center gap-3 flex-wrap">
                            <AppBadge :color="DOCUMENT_STATUS_BADGE[viewingDoc.status]">{{ viewingDoc.statusLabel }}</AppBadge>
                            <span v-if="viewingDoc.reference" class="text-xs text-muted font-mono">{{ viewingDoc.reference }}</span>
                        </div>

                        <p v-if="viewingDoc.description" class="text-sm text-secondary leading-relaxed">{{ viewingDoc.description }}</p>

                        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                            <div v-if="viewingDoc.categoryName">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.category") }}</dt>
                                <dd class="text-primary">{{ viewingDoc.categoryName }}</dd>
                            </div>
                            <div v-if="viewingDoc.folderName">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.folder") }}</dt>
                                <dd class="text-primary flex items-center gap-1"><Folder class="w-3.5 h-3.5 text-muted shrink-0" :stroke-width="2" /> {{ viewingDoc.folderName }}</dd>
                            </div>
                            <div v-if="viewingDoc.fileName">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.file") }}</dt>
                                <dd class="text-secondary truncate" :title="viewingDoc.fileName">{{ viewingDoc.fileName }}</dd>
                            </div>
                            <div v-if="viewingDoc.fileSize">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.size") }}</dt>
                                <dd class="text-secondary tabular-nums">{{ formatSize(viewingDoc.fileSize) }}</dd>
                            </div>
                            <div v-if="viewingDoc.width && viewingDoc.height">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.dimensions") }}</dt>
                                <dd class="text-secondary tabular-nums">{{ viewingDoc.width }}×{{ viewingDoc.height }}</dd>
                            </div>
                            <div v-if="viewingDoc.fileMime">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.type") }}</dt>
                                <dd class="text-secondary">{{ viewingDoc.fileMime }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("shared.common.created") }}</dt>
                                <dd class="text-secondary">{{ formatDate(viewingDoc.createdAt) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("shared.common.updated") }}</dt>
                                <dd class="text-secondary">{{ formatDate(viewingDoc.updatedAt) }}</dd>
                            </div>
                        </dl>

                        <dl v-if="viewingDoc.fileMime?.startsWith('image/') && (viewingDoc.alt || viewingDoc.caption)" class="space-y-3 text-sm">
                            <div v-if="viewingDoc.alt">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.alt") }}</dt>
                                <dd class="text-primary">{{ viewingDoc.alt }}</dd>
                            </div>
                            <div v-if="viewingDoc.caption">
                                <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.caption") }}</dt>
                                <dd class="text-primary">{{ viewingDoc.caption }}</dd>
                            </div>
                        </dl>

                        <div v-if="viewingDoc.fileUrl">
                            <dt class="text-xs text-muted uppercase tracking-wide mb-0.5">{{ t("backend.ged.documents.permalink") }}</dt>
                            <div class="flex items-center gap-2">
                                <code class="text-xs text-secondary bg-surface-2 rounded px-2 py-1 truncate flex-1">{{ permalinkFor(viewingDoc) }}</code>
                                <AppIconButton color="default" :title="t('shared.common.copy')" v-on:click="copy(permalinkFor(viewingDoc))">
                                    <Copy class="w-4 h-4" :stroke-width="2" />
                                </AppIconButton>
                            </div>
                        </div>

                        <div v-if="viewingDoc.tags?.length" class="flex flex-wrap gap-1.5">
                            <DocumentTagChip v-for="tag in viewingDoc.tags" :key="tag.id" :tag="tag" />
                        </div>

                        <div v-if="viewingDocVersions.length > 1" class="space-y-2">
                            <p class="text-xs text-muted uppercase tracking-wide">{{ t("backend.ged.documents.versions") }}</p>
                            <div class="divide-y divide-line/40 rounded-lg border border-line overflow-hidden">
                                <div
                                    v-for="version in viewingDocVersions"
                                    :key="version.id"
                                    class="flex items-center gap-3 px-3 py-2 text-sm"
                                    :class="version.versionNumber === viewingDocVersions[0].versionNumber ? 'bg-accent/5' : 'bg-surface'"
                                >
                                    <span class="shrink-0 text-xs font-mono font-medium px-1.5 py-0.5 rounded bg-surface-2 text-secondary">v{{ version.versionNumber }}</span>
                                    <span class="flex-1 truncate text-primary text-xs">{{ version.fileName }}</span>
                                    <span class="text-xs text-muted shrink-0">{{ formatDate(version.createdAt) }}</span>
                                    <a :href="version.fileUrl" target="_blank" download class="shrink-0 text-xs text-accent hover:underline flex items-center gap-0.5">
                                        <Download class="w-3 h-3" :stroke-width="2" />
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div v-if="viewingDocUsage && viewingDocUsage.total > 0" class="space-y-2">
                            <p class="text-xs text-muted uppercase tracking-wide">{{ t("backend.ged.documents.usage_title") }} ({{ viewingDocUsage.total }})</p>
                            <div class="divide-y divide-line/40 rounded-lg border border-line overflow-hidden">
                                <template v-for="group in viewingDocUsage.groups" :key="group.type">
                                    <component
                                        :is="item.href ? 'a' : 'div'"
                                        v-for="(item, index) in group.items"
                                        :key="group.type + '-' + index"
                                        :href="item.href || undefined"
                                        class="flex items-center gap-3 px-3 py-2 text-xs"
                                        :class="item.href ? 'hover:bg-surface-2 transition' : ''"
                                    >
                                        <span class="shrink-0 text-muted">{{ item.detail }}</span>
                                        <span class="flex-1 truncate text-primary">{{ item.label }}</span>
                                        <ExternalLink v-if="item.href" class="w-3 h-3 text-muted shrink-0" :stroke-width="2" />
                                    </component>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="viewingDoc = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.close") }}</AppButton>
                    <AppButton
                        v-if="cropPath && can('ged.documents.edit') && viewingDoc?.fileMime?.startsWith('image/')"
                        variant="ghost"
                        size="md"
                        v-on:click="cropTarget = viewingDoc"
                    >
                        <Crop class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.ged.documents.crop") }}
                    </AppButton>
                    <AppButton
                        v-if="viewingDoc?.fileUrl"
                        variant="ghost"
                        size="md"
                        v-on:click="openQr(viewingDoc)"
                    >
                        <QrCode class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.qr_code") }}
                    </AppButton>
                    <AppButton
                        v-if="viewingDoc?.fileUrl"
                        variant="secondary"
                        size="md"
                        :href="viewingDoc.fileUrl"
                        download
                    >
                        <Download class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.download") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppQrCodeModal :item="qrDoc" v-on:close="closeQr" />

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
